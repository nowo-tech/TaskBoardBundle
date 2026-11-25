<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use InvalidArgumentException;
use JsonException;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Http\ProviderHttpClient;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpAuthorization;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function ctype_digit;
use function http_build_query;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;
use function strlen;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PHP_QUERY_RFC3986;

/**
 * Since 3.1.0 (CU-1, Q-CU-1 option A): the stateless helper a host calls from **its own** routes to connect ClickUp
 * by OAuth 2 (authorization code). The bundle has no callback route and stores neither the token nor the app's
 * client secret: the host passes the client id / secret of its instance-wide ClickUp app on each call and stores the
 * returned token encrypted.
 *
 * - {@see self::authorizationUrl()}: the consent URL (constant base, `state` always sent; ClickUp has no PKCE and no
 *   scopes, so the protection is the confidential client plus the single-use {@see OAuthState}).
 * - {@see self::exchange()}: POST to the constant token URL with the parameters in the JSON body (never the query
 *   string), no redirects, 10 s, 16 KiB answer cap; one string `access_token` of printable ASCII (≤ 512 bytes).
 * - {@see self::describe()}: `GET /user` and `GET /team` (the authorising user and the workspaces they authorised).
 *
 * Exceptions carry a code and the HTTP status, never a response body. No state between calls (FrankenPHP
 * worker-safe with and without kernel reset).
 */
final readonly class ClickUpOAuthClient
{
    /** ClickUp's consent page (constant, never configuration or input). */
    public const AUTHORIZE_URI = 'https://app.clickup.com/api';

    /** ClickUp's code exchange endpoint (constant). */
    public const TOKEN_URI = ClickUpTaskProvider::BASE_URI . 'oauth/token';

    /** Seconds the code exchange may take (REQ-RUNTIME-001: below any request time limit). */
    public const EXCHANGE_TIMEOUT = 10.0;

    /** The largest code exchange answer read. */
    public const MAX_EXCHANGE_BYTES = 16_384;

    private const CLIENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    /** An authorization code or an access token: 1-512 printable ASCII characters. */
    private const OPAQUE_PATTERN = '/^[\x21-\x7e]{1,512}$/D';

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param HttpClientInterface $client the bundle's own client (never the profiler-traced one: it records bodies)
     * @param bool $allowLoopbackRedirect `http://localhost` / `127.0.0.1` redirect URIs (the bundle passes `kernel.debug`)
     */
    public function __construct(
        private ProviderHttpClient $http,
        private HttpClientInterface $client,
        private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer(),
        private bool $allowLoopbackRedirect = false,
    ) {
    }

    /**
     * The URL of ClickUp's consent page, for the host's start route to redirect to.
     *
     * @param string $redirectUri the host's callback URL, exactly as registered with the ClickUp app: `https://`, no
     *                            user info, no fragment (`http://localhost` only with `allowLoopbackRedirect`)
     *
     * @throws InvalidArgumentException for a malformed client id or redirect URI
     */
    public function authorizationUrl(string $clientId, string $redirectUri, OAuthState $state): string
    {
        $this->checkClientId($clientId);
        $this->checkRedirectUri($redirectUri);

        return self::AUTHORIZE_URI . '?' . http_build_query(['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state->value], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the callback's code for an access token. Call it only after the host checked the `state`.
     *
     * @throws InvalidArgumentException for a malformed client id or an empty client secret (host configuration)
     * @throws OAuthException `oauth_exchange_failed` (malformed code, refused code, transport error, bad answer)
     */
    public function exchange(string $clientId, #[SensitiveParameter] string $clientSecret, #[SensitiveParameter] string $code): OAuthGrant
    {
        $this->checkClientId($clientId);
        if ($clientSecret === '') {
            throw new InvalidArgumentException('The ClickUp client secret is empty.');
        }
        if (preg_match(self::OPAQUE_PATTERN, $code) !== 1) {
            throw new OAuthException(OAuthErrorCode::ExchangeFailed);
        }

        try {
            $response = $this->client->request('POST', self::TOKEN_URI, [
                'json'          => ['client_id' => $clientId, 'client_secret' => $clientSecret, 'code' => $code],
                'headers'       => ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout'       => self::EXCHANGE_TIMEOUT,
                'max_duration'  => self::EXCHANGE_TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw new OAuthException(OAuthErrorCode::ExchangeFailed, $status);
            }
            $body = $this->body($response, $status);
        } catch (OAuthException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new OAuthException(OAuthErrorCode::ExchangeFailed);
        }

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OAuthException(OAuthErrorCode::ExchangeFailed, $status);
        }
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!is_string($token) || preg_match(self::OPAQUE_PATTERN, $token) !== 1) {
            throw new OAuthException(OAuthErrorCode::ExchangeFailed, $status);
        }

        return new OAuthGrant($token);
    }

    /**
     * The authorising user (id and name, never the e-mail) and the workspaces the token may read (`GET /team`).
     * Workspaces with a malformed id are left out.
     *
     * @throws OAuthException `oauth_token_rejected` when ClickUp answers 401
     * @throws ProviderException any other provider failure (rate limit, timeout, unexpected answer, unknown scheme)
     */
    public function describe(ProviderCredential $credential): OAuthIdentity
    {
        $headers = ['Authorization' => ClickUpAuthorization::header($credential)];
        try {
            $me    = $this->http->getJson(ClickUpTaskProvider::BASE_URI . 'user', [], $headers);
            $teams = $this->http->getJson(ClickUpTaskProvider::BASE_URI . 'team', [], $headers);
        } catch (ProviderException $exception) {
            if ($exception->errorCode === ProviderErrorCode::AuthFailed) {
                throw new OAuthException(OAuthErrorCode::TokenRejected, 401);
            }

            throw $exception;
        }

        $user   = is_array($me['user'] ?? null) ? $me['user'] : [];
        $userId = $this->id($user['id'] ?? null);
        if ($userId === null) {
            throw new ProviderException(ProviderErrorCode::InvalidResponse);
        }

        $workspaces = [];
        foreach (is_array($teams['teams'] ?? null) ? $teams['teams'] : [] as $team) {
            $id = is_array($team) ? $this->id($team['id'] ?? null) : null;
            if ($id !== null) {
                $workspaces[] = new OAuthWorkspace($id, $this->sanitizer->line(is_string($team['name'] ?? null) ? $team['name'] : '', 255));
            }
        }

        return new OAuthIdentity($userId, $this->sanitizer->line(is_string($user['username'] ?? null) ? $user['username'] : '', 255), $workspaces);
    }

    private function body(ResponseInterface $response, int $status): string
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_string($length) && ctype_digit($length) && (int) $length > self::MAX_EXCHANGE_BYTES) {
            $response->cancel();

            throw new OAuthException(OAuthErrorCode::ExchangeFailed, $status);
        }
        $body = '';
        foreach ($this->client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > self::MAX_EXCHANGE_BYTES) {
                $response->cancel();

                throw new OAuthException(OAuthErrorCode::ExchangeFailed, $status);
            }
        }

        return $body;
    }

    private function id(mixed $value): ?string
    {
        $id = is_int($value) ? (string) $value : $value;

        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    private function checkClientId(string $clientId): void
    {
        if (preg_match(self::CLIENT_ID_PATTERN, $clientId) !== 1) {
            throw new InvalidArgumentException('The ClickUp client id is malformed.');
        }
    }

    private function checkRedirectUri(string $redirectUri): void
    {
        $parts  = preg_match('/[\s\x00-\x1f\x7f\\\\]/', $redirectUri) === 1 ? false : parse_url($redirectUri);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        $host   = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $valid  = is_array($parts) && $host !== '' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && ($scheme === 'https' || ($scheme === 'http' && $this->allowLoopbackRedirect && in_array($host, self::LOOPBACK_HOSTS, true)));
        if (!$valid) {
            throw new InvalidArgumentException('The OAuth redirect URI must be an https:// URL without user info or fragment.');
        }
    }
}
