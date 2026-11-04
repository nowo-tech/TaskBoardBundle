<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Http\SystemSleeper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function str_repeat;

/**
 * Since 1.23 (contract §4): retries, rate limits, caps and errors of the provider client — never a credential in an
 * exception.
 */
final class ProviderHttpClientTest extends TestCase
{
    private RecordingSleeper $sleeper;

    /** @var list<array<string, mixed>> */
    private array $options = [];

    public function testAJsonAnswerAndTheRequestOptions(): void
    {
        $data = $this->client([new MockResponse('{"teams": []}')])->getJson('https://api.clickup.com/api/v2/team', ['page' => 1], ['Authorization' => 'pk_secret']);

        self::assertSame(['teams' => []], $data);
        self::assertSame(0, $this->options[0]['max_redirects']);
        self::assertSame(10.0, $this->options[0]['timeout']);
        self::assertSame(30.0, $this->options[0]['max_duration']);
    }

    public function testRateLimitsWaitInlineOrPauseTheRun(): void
    {
        $limited = static fn (array $headers): MockResponse => new MockResponse('', ['http_code' => 429, 'response_headers' => $headers]);

        $this->client([$limited(['retry-after' => '3']), $limited(['x-ratelimit-reset' => '1790000010']), $limited([]), new MockResponse('[]')], maxInlineWait: 60)
            ->getJson('https://api.test/x', [], []);
        self::assertSame([3, 10, 60], $this->sleeper->waits);

        try {
            $this->client([$limited(['retry-after' => '120'])])->getJson('https://api.test/x', [], ['Authorization' => 'pk_secret']);
            self::fail('long wait');
        } catch (RateLimitedException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
            self::assertSame(ProviderErrorCode::RateLimited, $exception->code());
            self::assertStringNotContainsString('pk_secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        $many = [];
        for ($i = 0; $i < 6; ++$i) {
            $many[] = $limited(['retry-after' => '1']);
        }
        $this->expectException(RateLimitedException::class);
        $this->client($many)->getJson('https://api.test/x', [], []);
    }

    public function testRemainingZeroWaitsForTheReset(): void
    {
        $this->client([new MockResponse('[]', ['response_headers' => ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1790000500']])])->getJson('https://api.test/x', [], []);
        self::assertSame([30], $this->sleeper->waits, 'capped at the inline wait');
        $this->client([new MockResponse('[]', ['response_headers' => ['x-ratelimit-remaining' => '0']])])->getJson('https://api.test/x', [], []);
        self::assertSame([], $this->sleeper->waits);
    }

    public function testServerAndTransportErrorsAreRetriedThenFail(): void
    {
        $data = $this->client([new MockResponse('', ['http_code' => 502]), new MockResponse('', ['error' => 'reset']), new MockResponse('{"ok": true}')])->getJson('https://api.test/x', [], []);
        self::assertSame(['ok' => true], $data);
        self::assertCount(2, $this->sleeper->waits);

        $this->assertCode(ProviderErrorCode::ServerError, [new MockResponse('', ['http_code' => 500]), new MockResponse('', ['http_code' => 500]), new MockResponse('', ['http_code' => 500]), new MockResponse('', ['http_code' => 503])]);
        $this->assertCode(ProviderErrorCode::Transport, [new MockResponse('', ['error' => 'x']), new MockResponse('', ['error' => 'x']), new MockResponse('', ['error' => 'x']), new MockResponse('', ['error' => 'x'])]);
        // A timeout before the status line: retried, then reported as a timeout.
        $client = new ProviderHttpClient(new MockHttpClient(static function (): never {
            throw new TimeoutException('idle');
        }), $sleeper = new RecordingSleeper(), new MockClock());
        try {
            $client->getJson('https://api.test/x', [], []);
            self::fail('timeout');
        } catch (ProviderException $exception) {
            self::assertSame(ProviderErrorCode::Timeout, $exception->errorCode);
            self::assertCount(3, $sleeper->waits);
        }
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $this->assertCode(ProviderErrorCode::AuthFailed, [new MockResponse('{"err": "Token invalid pk_secret"}', ['http_code' => 401])]);
        $this->assertCode(ProviderErrorCode::Forbidden, [new MockResponse('', ['http_code' => 403])]);
        $this->assertCode(ProviderErrorCode::NotFound, [new MockResponse('', ['http_code' => 404])]);
        $this->assertCode(ProviderErrorCode::InvalidResponse, [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://evil.test/']])]);
        $this->assertCode(ProviderErrorCode::InvalidResponse, [new MockResponse('', ['http_code' => 418])]);
        self::assertSame([], $this->sleeper->waits);
    }

    public function testBodiesAreCappedAndMustBeJsonObjects(): void
    {
        $this->assertCode(ProviderErrorCode::ResponseTooLarge, [new MockResponse(str_repeat(' ', 2000) . '[]', ['response_headers' => ['content-length' => '2002']])], 1024);
        $this->assertCode(ProviderErrorCode::ResponseTooLarge, [new MockResponse([str_repeat('a', 800), str_repeat('b', 800)])], 1024);
        $this->assertCode(ProviderErrorCode::InvalidResponse, [new MockResponse('not json')]);
        $this->assertCode(ProviderErrorCode::InvalidResponse, [new MockResponse('"a string"')]);
        $this->assertCode(ProviderErrorCode::InvalidResponse, [new MockResponse(str_repeat('[', 70) . str_repeat(']', 70))]);
        $this->assertCode(ProviderErrorCode::Transport, [new MockResponse([new TransportException('cut mid-body')])]);
        $this->assertCode(ProviderErrorCode::Transport, [new MockResponse(['{', new TimeoutException('idle')])]);
    }

    public function testEveryCodeHasAFixedMessage(): void
    {
        foreach (ProviderErrorCode::cases() as $code) {
            self::assertSame($code->message(), (new ProviderException($code))->getMessage());
        }
        (new SystemSleeper())->sleep(0);
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function assertCode(ProviderErrorCode $code, array $responses, int $maxBytes = 8_388_608): void
    {
        try {
            $this->client($responses, maxBytes: $maxBytes)->getJson('https://api.test/x', [], ['Authorization' => 'pk_secret']);
            self::fail('no error: ' . $code->value);
        } catch (ProviderException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertStringNotContainsString('pk_secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, int $maxInlineWait = 30, int $maxBytes = 8_388_608): ProviderHttpClient
    {
        $this->sleeper = new RecordingSleeper();
        $this->options = [];

        return new ProviderHttpClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
                $this->options[] = $options;

                return array_shift($responses) ?? new MockResponse('', ['http_code' => 404]);
            }),
            $this->sleeper,
            new MockClock(new DateTimeImmutable('@1790000000')),
            maxResponseBytes: $maxBytes,
            maxInlineWait: $maxInlineWait,
        );
    }
}
