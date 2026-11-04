<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Http;

use JsonException;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function ctype_digit;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function random_int;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Since 1.23: the GET-JSON client of provider adapters. Every call: fixed base URI (the adapter's constant), no
 * redirects, idle timeout and total duration, a response size cap (Content-Length and while streaming), JSON depth
 * 64, and:
 *
 * - 429: waits `Retry-After` (else `X-RateLimit-Reset` − now, else 60 s) when that is at most `maxInlineWait`, at most
 *   5 times per call; otherwise {@see RateLimitedException}. `X-RateLimit-Remaining: 0` waits for the reset first;
 * - 5xx and transport errors: 3 retries after 1, 2 and 4 s (plus jitter);
 * - 401 / 403 / 404 / 3xx / other: {@see ProviderException} with a fixed message, never retried.
 *
 * Exceptions never carry the request (headers, credential) nor the body.
 */
final readonly class ProviderHttpClient
{
    private const MAX_RATE_RETRIES = 5;

    private const MAX_SERVER_RETRIES = 3;

    public function __construct(
        private HttpClientInterface $client,
        private SleeperInterface $sleeper = new SystemSleeper(),
        private ClockInterface $clock = new NativeClock(),
        private float $timeout = 10.0,
        private float $maxDuration = 30.0,
        private int $maxResponseBytes = 8_388_608,
        private int $maxInlineWait = 30,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     *
     * @throws ProviderException
     *
     * @return array<mixed>
     */
    public function getJson(string $url, array $query, array $headers): array
    {
        $rateRetries   = 0;
        $serverRetries = 0;
        while (true) {
            try {
                $response = $this->client->request('GET', $url, [
                    'query'         => $query,
                    'headers'       => $headers + ['Accept' => 'application/json'],
                    'max_redirects' => 0,
                    'timeout'       => $this->timeout,
                    'max_duration'  => $this->maxDuration,
                ]);
                $status          = $response->getStatusCode();
                $responseHeaders = $response->getHeaders(false);
            } catch (Throwable $exception) {
                $code = $exception instanceof TimeoutExceptionInterface ? ProviderErrorCode::Timeout : ProviderErrorCode::Transport;
                if ($serverRetries++ < self::MAX_SERVER_RETRIES) {
                    $this->backOff($serverRetries);
                    continue;
                }

                throw new ProviderException($code);
            }

            if ($status === 429) {
                $response->cancel();
                $wait = $this->retryAfter($responseHeaders);
                if ($wait > $this->maxInlineWait || $rateRetries++ >= self::MAX_RATE_RETRIES) {
                    throw new RateLimitedException($wait);
                }
                $this->sleeper->sleep($wait);
                continue;
            }
            if ($status >= 500) {
                $response->cancel();
                if ($serverRetries++ < self::MAX_SERVER_RETRIES) {
                    $this->backOff($serverRetries);
                    continue;
                }

                throw new ProviderException(ProviderErrorCode::ServerError);
            }
            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw new ProviderException(match ($status) {
                    401 => ProviderErrorCode::AuthFailed, 403 => ProviderErrorCode::Forbidden, 404 => ProviderErrorCode::NotFound, default => ProviderErrorCode::InvalidResponse,
                });
            }

            $data = $this->decode($this->body($response, $responseHeaders));
            if (($responseHeaders['x-ratelimit-remaining'][0] ?? null) === '0') {
                $wait = $this->secondsUntilReset($responseHeaders);
                if ($wait !== null && $wait > 0) {
                    $this->sleeper->sleep(min($wait, $this->maxInlineWait));
                }
            }

            return $data;
        }
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function body(ResponseInterface $response, array $headers): string
    {
        $length = $headers['content-length'][0] ?? null;
        if (is_string($length) && ctype_digit($length) && (int) $length > $this->maxResponseBytes) {
            $response->cancel();

            throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
        }
        $body = '';
        try {
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > $this->maxResponseBytes) {
                    $response->cancel();

                    throw new ProviderException(ProviderErrorCode::ResponseTooLarge);
                }
            }
        } catch (ProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ProviderException($exception instanceof TimeoutExceptionInterface ? ProviderErrorCode::Timeout : ProviderErrorCode::Transport);
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderException(ProviderErrorCode::InvalidResponse);
        }
        if (!is_array($data)) {
            throw new ProviderException(ProviderErrorCode::InvalidResponse);
        }

        return $data;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfter(array $headers): int
    {
        $retryAfter = trim((string) ($headers['retry-after'][0] ?? ''));
        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        return $this->secondsUntilReset($headers) ?? 60;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function secondsUntilReset(array $headers): ?int
    {
        $reset = trim((string) ($headers['x-ratelimit-reset'][0] ?? ''));
        if ($reset === '' || !ctype_digit($reset)) {
            return null;
        }

        return max(1, (int) $reset - $this->clock->now()->getTimestamp());
    }

    private function backOff(int $attempt): void
    {
        $this->sleeper->sleep((1 << ($attempt - 1)) + random_int(0, 1));
    }
}
