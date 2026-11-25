<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync\OAuth;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\OAuth\ClickUpOAuthClient;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthErrorCode;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthException;
use Nowo\TaskBoardBundle\Sync\OAuth\OAuthState;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Tests\Unit\Sync\RecordingSleeper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_column;
use function array_shift;
use function array_unique;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_repeat;

use const PHP_URL_QUERY;

/**
 * Since 3.1.0 (CU-1): the stateless ClickUp OAuth helper — consent URL, code exchange (POST, secret in the body),
 * identity, and the GET-only rule of the adapter. Placeholders only: no real client id, secret or token.
 */
final class ClickUpOAuthClientTest extends TestCase
{
    private const CLIENT_ID = 'CLIENT_ID_PLACEHOLDER';

    private const SECRET = 'client-secret-placeholder';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testTheAuthorizationUrlCarriesClientRedirectAndState(): void
    {
        $state = OAuthState::create();
        $url   = $this->client([])->authorizationUrl(self::CLIENT_ID, 'https://app.example.test/oauth/clickup/callback?x=1', $state);

        self::assertStringStartsWith('https://app.clickup.com/api?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['client_id' => self::CLIENT_ID, 'redirect_uri' => 'https://app.example.test/oauth/clickup/callback?x=1', 'state' => $state->value], $query);

        self::assertStringStartsWith('https://app.clickup.com/api?', $this->client([], loopback: true)->authorizationUrl(self::CLIENT_ID, 'http://localhost:8000/cb', $state));
        self::assertStringStartsWith('https://app.clickup.com/api?', $this->client([], loopback: true)->authorizationUrl(self::CLIENT_ID, 'http://127.0.0.1/cb', $state));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function refusedAuthorizations(): iterable
    {
        yield 'empty client id' => ['', 'https://app.example.test/cb', false];
        yield 'client id with a slash' => ['a/b', 'https://app.example.test/cb', false];
        yield 'client id with a newline' => ["abc\n", 'https://app.example.test/cb', false];
        yield 'plain http' => [self::CLIENT_ID, 'http://app.example.test/cb', false];
        yield 'loopback http without debug' => [self::CLIENT_ID, 'http://localhost/cb', false];
        yield 'non-loopback http with debug' => [self::CLIENT_ID, 'http://app.example.test/cb', true];
        yield 'user info' => [self::CLIENT_ID, 'https://user:pass@app.example.test/cb', false];
        yield 'user only' => [self::CLIENT_ID, 'https://user@app.example.test/cb', false];
        yield 'fragment' => [self::CLIENT_ID, 'https://app.example.test/cb#frag', false];
        yield 'relative' => [self::CLIENT_ID, '/cb', false];
        yield 'white space' => [self::CLIENT_ID, 'https://app.example.test/c b', false];
        yield 'backslash' => [self::CLIENT_ID, 'https://app.example.test\\@evil.test/cb', false];
        yield 'other scheme' => [self::CLIENT_ID, 'javascript://app.example.test/%0aalert(1)', false];
        yield 'unparsable' => [self::CLIENT_ID, 'https://:80', false];
    }

    #[DataProvider('refusedAuthorizations')]
    public function testMalformedClientIdsAndRedirectUrisAreRefused(string $clientId, string $redirectUri, bool $loopback): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client([], loopback: $loopback)->authorizationUrl($clientId, $redirectUri, OAuthState::create());
    }

    public function testTheExchangePostsTheSecretInTheBodyAndReturnsTheGrant(): void
    {
        $grant = $this->client([new MockResponse('{"access_token": "tok_placeholder_abcd"}', ['response_headers' => ['content-type' => 'application/json']])])
            ->exchange(self::CLIENT_ID, self::SECRET, 'code_placeholder');

        self::assertSame('tok_placeholder_abcd', $grant->accessToken());
        self::assertNull($grant->expiresAt);
        self::assertNull($grant->refreshToken());

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(ClickUpOAuthClient::TOKEN_URI, $this->requests[0]['url'], 'nothing in the query string');
        self::assertSame('https://api.clickup.com/api/v2/oauth/token', ClickUpOAuthClient::TOKEN_URI);
        self::assertSame(['client_id' => self::CLIENT_ID, 'client_secret' => self::SECRET, 'code' => 'code_placeholder'], json_decode((string) $this->requests[0]['options']['body'], true));
        self::assertSame(0, $this->requests[0]['options']['max_redirects']);
        self::assertSame(10.0, $this->requests[0]['options']['timeout']);
        self::assertSame(10.0, $this->requests[0]['options']['max_duration']);
    }

    public function testExchangeInputErrorsNeverReachClickUp(): void
    {
        $client = $this->client([]);
        foreach (['', 'has space', "code\n", str_repeat('c', 513)] as $code) {
            try {
                $client->exchange(self::CLIENT_ID, self::SECRET, $code);
                self::fail('malformed code');
            } catch (OAuthException $exception) {
                self::assertSame(OAuthErrorCode::ExchangeFailed, $exception->errorCode);
                self::assertNull($exception->httpStatus);
            }
        }
        try {
            $client->exchange('bad id', self::SECRET, 'code');
            self::fail('client id');
        } catch (InvalidArgumentException) {
        }
        try {
            $client->exchange(self::CLIENT_ID, '', 'code');
            self::fail('secret');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('code', $exception->getMessage());
        }
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{MockResponse, int|null}>
     */
    public static function failedExchanges(): iterable
    {
        yield 'refused code' => [new MockResponse('{"err": "Code already used client-secret-placeholder", "ECODE": "OAUTH_015"}', ['http_code' => 400]), 400];
        yield 'unauthorised' => [new MockResponse('{"err": "x"}', ['http_code' => 401]), 401];
        yield 'rate limited' => [new MockResponse('', ['http_code' => 429]), 429];
        yield 'server error' => [new MockResponse('', ['http_code' => 503]), 503];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://evil.test/']]), 302];
        yield 'transport' => [new MockResponse([new TransportException('boom client-secret-placeholder')]), null];
        yield 'declared too large' => [new MockResponse(str_repeat(' ', 20_000), ['response_headers' => ['content-length' => '20000']]), 200];
        yield 'streamed too large' => [new MockResponse(['{"access_token": "', str_repeat('a', 16_400), '"}']), 200];
        yield 'not json' => [new MockResponse('<html>'), 200];
        yield 'json string' => [new MockResponse('"tok"'), 200];
        yield 'no token' => [new MockResponse('{"token": "tok"}'), 200];
        yield 'numeric token' => [new MockResponse('{"access_token": 1234}'), 200];
        yield 'token with a space' => [new MockResponse('{"access_token": "a b"}'), 200];
        yield 'empty token' => [new MockResponse('{"access_token": ""}'), 200];
        yield 'token too long' => [new MockResponse('{"access_token": "' . str_repeat('a', 513) . '"}'), 200];
        yield 'too deep' => [new MockResponse(str_repeat('[', 20) . str_repeat(']', 20)), 200];
    }

    #[DataProvider('failedExchanges')]
    public function testFailedExchangesCarryACodeAndTheStatusNeverTheBody(MockResponse $response, ?int $status): void
    {
        try {
            $this->client([$response])->exchange(self::CLIENT_ID, self::SECRET, 'code_placeholder');
            self::fail('exchange');
        } catch (OAuthException $exception) {
            self::assertSame(OAuthErrorCode::ExchangeFailed, $exception->errorCode);
            self::assertSame($status, $exception->httpStatus);
            self::assertSame('The provider did not complete the connection.', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testDescribeReadsTheUserAndTheAuthorisedWorkspacesWithABearerToken(): void
    {
        $identity = $this->client([
            new MockResponse((string) json_encode(['user' => ['id' => 81, 'username' => "Ada \u{202E}Lovelace", 'email' => 'ada@example.test']])),
            new MockResponse((string) json_encode(['teams' => [['id' => '9001', 'name' => 'Acme'], ['id' => 9002, 'name' => null], ['id' => '../x', 'name' => 'Bad'], 'junk', ['name' => 'No id']]])),
        ])->describe(new ProviderCredential('tok_placeholder', 'conn-1', ['scheme' => 'oauth']));

        self::assertSame('81', $identity->userId);
        self::assertSame('Ada Lovelace', $identity->userName);
        self::assertSame(['9001', '9002'], $identity->workspaceIds());
        self::assertSame('Acme', $identity->workspaces[0]->name);
        self::assertSame('', $identity->workspaces[1]->name);

        self::assertSame(['GET'], array_unique(array_column($this->requests, 'method')));
        self::assertSame([ClickUpTaskProvider::BASE_URI . 'user', ClickUpTaskProvider::BASE_URI . 'team'], array_column($this->requests, 'url'));
        self::assertSame('Authorization: Bearer tok_placeholder', $this->requests[0]['options']['normalized_headers']['authorization'][0]);

        $personal = $this->client([new MockResponse('{"user": {"id": "u1"}}'), new MockResponse('{}')])->describe(new ProviderCredential('pk_placeholder', 'conn-2'));
        self::assertSame('u1', $personal->userId);
        self::assertSame('', $personal->userName);
        self::assertSame([], $personal->workspaces);
        self::assertSame('Authorization: pk_placeholder', $this->requests[0]['options']['normalized_headers']['authorization'][0]);
    }

    public function testDescribeFailures(): void
    {
        try {
            $this->client([new MockResponse('{"err": "Token invalid", "ECODE": "OAUTH_019"}', ['http_code' => 401])])->describe(new ProviderCredential('tok', 'c', ['scheme' => 'oauth']));
            self::fail('401');
        } catch (OAuthException $exception) {
            self::assertSame(OAuthErrorCode::TokenRejected, $exception->errorCode);
            self::assertSame(401, $exception->httpStatus);
        }

        try {
            $this->client([new MockResponse('', ['http_code' => 403])])->describe(new ProviderCredential('tok', 'c', ['scheme' => 'oauth']));
            self::fail('403');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::Forbidden, $exception->code());
        }

        try {
            $this->client([new MockResponse('{"user": {"id": "a b"}}'), new MockResponse('{"teams": []}')])->describe(new ProviderCredential('tok', 'c', ['scheme' => 'oauth']));
            self::fail('no user id');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::InvalidResponse, $exception->code());
        }

        try {
            $this->client([])->describe(new ProviderCredential('tok', 'c', ['scheme' => 'basic']));
            self::fail('scheme');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::UnsupportedCredential, $exception->code());
            self::assertSame([], $this->requests, 'refused before any request');
        }
    }

    /**
     * @return iterable<string, array{MockResponse|TimeoutException, OAuthErrorCode|ProviderErrorCode}>
     */
    public static function failedDescriptions(): iterable
    {
        yield 'rate limited' => [new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '600']]), OAuthErrorCode::RateLimited];
        yield 'unauthorised' => [new MockResponse('', ['http_code' => 401]), OAuthErrorCode::TokenRejected];
        yield 'forbidden' => [new MockResponse('', ['http_code' => 403]), ProviderErrorCode::Forbidden];
        yield 'not found' => [new MockResponse('', ['http_code' => 404]), ProviderErrorCode::NotFound];
        yield 'gone' => [new MockResponse('', ['http_code' => 410]), ProviderErrorCode::NotFound];
        yield 'server error' => [new MockResponse('', ['http_code' => 503]), ProviderErrorCode::ServerError];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://evil.test/']]), ProviderErrorCode::InvalidResponse];
        yield 'transport' => [new MockResponse([new TransportException('boom')]), ProviderErrorCode::Transport];
        yield 'timeout' => [new TimeoutException('slow'), ProviderErrorCode::Timeout];
        yield 'declared too large' => [new MockResponse(str_repeat(' ', 2_097_153), ['response_headers' => ['content-length' => '2097153']]), ProviderErrorCode::ResponseTooLarge];
        yield 'streamed too large' => [new MockResponse(['{"user": "', str_repeat('a', 2_097_152), '"}']), ProviderErrorCode::ResponseTooLarge];
        yield 'not json' => [new MockResponse('<html>'), ProviderErrorCode::InvalidResponse];
        yield 'json string' => [new MockResponse('"x"'), ProviderErrorCode::InvalidResponse];
    }

    /** Audit ZBT-L2: the interactive callback never waits, retries or follows the sync client's budget. */
    #[DataProvider('failedDescriptions')]
    public function testDescribeFailsAtOnceWithoutRetryOrWait(MockResponse|TimeoutException $response, OAuthErrorCode|ProviderErrorCode $code): void
    {
        try {
            $this->client([$response, new MockResponse('{"user": {"id": 1}}'), new MockResponse('{"teams": []}')])->describe(new ProviderCredential('tok', 'c', ['scheme' => 'oauth']));
            self::fail('describe');
        } catch (OAuthException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertNull($exception->getPrevious());
        } catch (ProviderException $exception) {
            self::assertSame($code, $exception->code());
            self::assertNull($exception->getPrevious());
        }
        self::assertCount(1, $this->requests, 'a single request: no retry');
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame(0, $this->requests[0]['options']['max_redirects']);
        self::assertSame(ClickUpOAuthClient::DESCRIBE_TIMEOUT, $this->requests[0]['options']['timeout']);
        self::assertSame(ClickUpOAuthClient::DESCRIBE_TIMEOUT, $this->requests[0]['options']['max_duration']);
        self::assertLessThanOrEqual(ClickUpOAuthClient::EXCHANGE_TIMEOUT, 2 * ClickUpOAuthClient::DESCRIBE_TIMEOUT);
    }

    public function testTheAdapterOnlyReadsAndSendsOAuthTokensAsBearer(): void
    {
        $responses = [
            new MockResponse('{"teams": [{"id": "9001", "name": "Acme"}]}'),
            new MockResponse('{"tasks": [], "last_page": true}'),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}');
        });
        $provider   = new ClickUpTaskProvider(new ProviderHttpClient($http, new RecordingSleeper(), new MockClock()));
        $credential = new ProviderCredential('tok_placeholder', 'conn-1', ['scheme' => 'oauth']);

        self::assertCount(1, $provider->listContainers($credential)->items);
        self::assertSame([], $provider->fetchTasks($credential, '900200', null, null)->tasks);

        self::assertSame(['GET'], array_unique(array_column($this->requests, 'method')), 'the adapter never writes to ClickUp');
        foreach ($this->requests as $request) {
            self::assertStringStartsWith(ClickUpTaskProvider::BASE_URI, $request['url']);
            self::assertSame('Authorization: Bearer tok_placeholder', $request['options']['normalized_headers']['authorization'][0]);
        }

        $this->requests = [];
        try {
            $provider->fetchTasks(new ProviderCredential('tok', 'c', ['scheme' => 'basic']), '900200', null, null);
            self::fail('scheme');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::UnsupportedCredential, $exception->code());
            self::assertSame([], $this->requests);
        }
    }

    /**
     * @param list<MockResponse|TimeoutException> $responses
     */
    private function client(array $responses, bool $loopback = false): ClickUpOAuthClient
    {
        $this->requests = [];
        $http           = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            $next = array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);

            return $next instanceof MockResponse ? $next : throw $next;
        });

        return new ClickUpOAuthClient($http, allowLoopbackRedirect: $loopback);
    }
}
