<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\OAuth;

use InvalidArgumentException;
use JsonException;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpAuthorization;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
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
 * - {@see self::exchange()}: POST to the constant token URL with the parameters in the JSON body, as ClickUp's
 *   reference documents them (`application/json` or form; never the query string, so the secret stays out of access
 *   logs), no redirects, 10 s, 16 KiB answer cap; one string `access_token` of printable ASCII (≤ 512 bytes).
 * - {@see self::describe()}: `GET /user` and `GET /team` (the authorising user and the workspaces they authorised),
 *   each one bounded request (5 s, no retry, a 429 fails at once as `oauth_rate_limited`).
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

    /** Seconds each of the two `describe()` calls may take (no retry): ≤ 10 s in all, like the exchange. */
    public const DESCRIBE_TIMEOUT = 5.0;

    /** The largest `describe()` answer read (`GET /team` lists the members of every workspace). */
    public const MAX_DESCRIBE_BYTES = 2_097_152;

    private const CLIENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';

    /** An authorization code or an access token: 1-512 printable ASCII characters. */
    private const OPAQUE_PATTERN = '/^[\x21-\x7e]{1,512}$/D';

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @param HttpClientInterface $client the bundle's own client (never the profiler-traced one: it records bodies)
     *                                    — every call here is a single bounded request, never the sync retry policy
     * @param bool $allowLoopbackRedirect `http://localhost` / `127.0.0.1` redirect URIs (the bundle passes `kernel.debug`)
     */
    public function __construct(
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
            $body = $this->body($response, self::MAX_EXCHANGE_BYTES) ?? throw new OAuthException(OAuthErrorCode::ExchangeFailed, $status);
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
     * Since ZBT-L2 it is bounded for the interactive callback: two GETs of at most {@see self::DESCRIBE_TIMEOUT}
     * seconds each (≤ 10 s in all, like {@see self::exchange()}), no retry and no inline rate-limit wait.
     *
     * @throws OAuthException `oauth_token_rejected` (401), `oauth_rate_limited` (429: ask the user to try again later)
     * @throws ProviderException any other failure (`forbidden`, `not_found`, `server_error`, `timeout`, `transport`,
     *                           `response_too_large`, `invalid_response`, `unsupported_credential`)
     */
    public function describe(ProviderCredential $credential): OAuthIdentity
    {
        $headers = ['Authorization' => ClickUpAuthorization::header($credential)];
        $me      = $this->getOnce('user', $headers);
        $teams   = $this->getOnce('team', $headers);

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

    /**
     * The answer, or null when it is larger than `$maxBytes` (declared or streamed; the response is cancelled).
     */
    private function body(ResponseInterface $response, int $maxBytes): ?string
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_string($length) && ctype_digit($length) && (int) $length > $maxBytes) {
            $response->cancel();

            return null;
        }
        $body = '';
        foreach ($this->client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (strlen($body) > $maxBytes) {
                $response->cancel();

                return null;
            }
        }

        return $body;
    }

    /**
     * Since ZBT-L2: one GET of the interactive callback — no retry, no inline rate-limit wait, no redirect, at most
     * {@see self::DESCRIBE_TIMEOUT} seconds — unlike the sync client's batch policy.
     *
     * @param array<string, string> $headers
     *
     * @throws OAuthException `oauth_token_rejected` (401), `oauth_rate_limited` (429)
     * @throws ProviderException any other failure, with the provider error code
     *
     * @return array<mixed>
     */
    private function getOnce(string $path, array $headers): array
    {
        try {
            $response = $this->client->request('GET', ClickUpTaskProvider::BASE_URI . $path, [
                'headers'       => $headers + ['Accept' => 'application/json'],
                'max_redirects' => 0,
                'timeout'       => self::DESCRIBE_TIMEOUT,
                'max_duration'  => self::DESCRIBE_TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw match (true) {
                    $status === 401                  => new OAuthException(OAuthErrorCode::TokenRejected, 401),
                    $status === 429                  => new OAuthException(OAuthErrorCode::RateLimited, 429),
                    $status === 403                  => new ProviderException(ProviderErrorCode::Forbidden),
                    $status === 404, $status === 410 => new ProviderException(ProviderErrorCode::NotFound),
                    $status >= 500                   => new ProviderException(ProviderErrorCode::ServerError),
                    default                          => new ProviderException(ProviderErrorCode::InvalidResponse),
                };
            }
            $body = $this->body($response, self::MAX_DESCRIBE_BYTES) ?? throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
        } catch (OAuthException|ProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ProviderException($exception instanceof TimeoutExceptionInterface ? ProviderErrorCode::Timeout : ProviderErrorCode::Transport);
        }

        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderException(ProviderErrorCode::InvalidResponse);
        }

        return is_array($data) ? $data : throw new ProviderException(ProviderErrorCode::InvalidResponse);
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
