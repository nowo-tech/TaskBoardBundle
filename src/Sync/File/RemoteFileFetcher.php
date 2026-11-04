<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\File;

use Nowo\TaskBoardBundle\Sync\Exception\UnsafeRemoteFileException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function basename;
use function fclose;
use function filter_var;
use function fopen;
use function fread;
use function fwrite;
use function hash_file;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function mb_strcut;
use function parse_url;
use function preg_match;
use function preg_replace;
use function rawurldecode;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const FILTER_VALIDATE_IP;

/**
 * Since 1.23: the only way the sync downloads a remote file (attachments, inline images) — SSRF-safe:
 *
 * 1. https only, no user info, default port, no IP literal;
 * 2. the host must be on the provider's allowlist ({@see HostAllowlist});
 * 3. the client refuses private networks (the DI wraps it in NoPrivateNetworkHttpClient) and the optional
 *    {@see UrlGuardInterface} checks and pins the address;
 * 4. no automatic redirect: at most `maxRedirects` hops, each one checked again from step 1;
 * 5. no credential is ever sent;
 * 6. the size is capped from the declared size, Content-Length and while streaming;
 * 7. the type is sniffed from the bytes: it must be allowed, and SVG, HTML and executables are always refused.
 */
final readonly class RemoteFileFetcher
{
    /** Always refused, whatever `allowed_mime_types` says. */
    public const DENIED_MIME_TYPES = [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'application/x-msdownload',
        'application/x-dosexec',
        'application/x-executable',
        'application/x-mach-binary',
        'application/x-sharedlib',
        'application/x-elf',
        'application/javascript',
        'text/javascript',
    ];

    public const DEFAULT_ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/zip',
    ];

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        private HttpClientInterface $client,
        private int $maxBytes = 26_214_400,
        private array $allowedMimeTypes = self::DEFAULT_ALLOWED_MIME_TYPES,
        private int $maxRedirects = 3,
        private float $timeout = 15.0,
        private float $maxDuration = 120.0,
        private UrlGuardInterface $urlGuard = new NullUrlGuard(),
    ) {
    }

    /**
     * @param list<string> $allowedHosts the provider's attachment hosts
     * @param int $budget the bytes the run may still download
     * @param bool $imagesOnly inline images: only image/* types (never SVG)
     *
     * @throws UnsafeRemoteFileException
     */
    public function fetch(array $allowedHosts, string $url, string $name, ?int $declaredSize, int $budget, bool $imagesOnly = false): FetchedFile
    {
        $cap = $budget < $this->maxBytes ? $budget : $this->maxBytes;
        if ($declaredSize !== null && $declaredSize > $this->maxBytes) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::TOO_LARGE);
        }
        if ($declaredSize !== null && $declaredSize > $budget) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::RUN_BUDGET);
        }
        $name = $this->safeName($name);
        if ($this->looksLikeSvgName($name)) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::TYPE_NOT_ALLOWED);
        }

        for ($hop = 0;; ++$hop) {
            $options  = $this->checkUrl($allowedHosts, $url);
            $response = $this->request($url, $options);
            $status   = $this->status($response);
            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? null;
                $response->cancel();
                if (!is_string($location) || $hop >= $this->maxRedirects) {
                    throw new UnsafeRemoteFileException(UnsafeRemoteFileException::TOO_MANY_HOPS);
                }
                $url = $this->resolve($url, $location);
                continue;
            }
            if ($status === 401 || $status === 403) {
                $response->cancel();

                throw new UnsafeRemoteFileException(UnsafeRemoteFileException::AUTH_REQUIRED);
            }
            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw new UnsafeRemoteFileException(UnsafeRemoteFileException::FAILED);
            }

            return $this->download($response, $name, $cap, $cap < $this->maxBytes, $imagesOnly);
        }
    }

    /**
     * Steps 1–3 for one URL; the extra client options of the URL guard.
     *
     * @param list<string> $allowedHosts
     *
     * @return array<string, mixed>
     */
    private function checkUrl(array $allowedHosts, string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::INVALID_URL);
        }
        $host = trim($parts['host'], '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::INVALID_URL);
        }
        if (!HostAllowlist::allows($allowedHosts, $host)) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::HOST_NOT_ALLOWED);
        }

        return $this->urlGuard->optionsFor($url);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $url, array $options): ResponseInterface
    {
        try {
            return $this->client->request('GET', $url, $options + [
                'max_redirects' => 0,
                'timeout'       => $this->timeout,
                'max_duration'  => $this->maxDuration,
                'headers'       => ['Accept' => '*/*'],
            ]);
        } catch (Throwable) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::FAILED);
        }
    }

    private function status(ResponseInterface $response): int
    {
        try {
            return $response->getStatusCode();
        } catch (Throwable) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::FAILED);
        }
    }

    /**
     * Step 6 and 7: streams the body to a temporary file, aborting at the cap, then sniffs the type.
     */
    private function download(ResponseInterface $response, string $name, int $cap, bool $capIsBudget, bool $imagesOnly): FetchedFile
    {
        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if (is_string($length) && preg_match('/^\d+$/D', $length) === 1 && (int) $length > $cap) {
            $response->cancel();

            throw new UnsafeRemoteFileException($capIsBudget ? UnsafeRemoteFileException::RUN_BUDGET : UnsafeRemoteFileException::TOO_LARGE);
        }

        $path   = (string) tempnam(sys_get_temp_dir(), 'tb-sync-');
        $handle = fopen($path, 'w') ?: throw new UnsafeRemoteFileException(UnsafeRemoteFileException::FAILED);
        $size   = 0;
        try {
            foreach ($this->client->stream($response) as $chunk) {
                $data = $chunk->getContent();
                $size += strlen($data);
                if ($size > $cap) {
                    $response->cancel();

                    throw new UnsafeRemoteFileException($capIsBudget ? UnsafeRemoteFileException::RUN_BUDGET : UnsafeRemoteFileException::TOO_LARGE);
                }
                fwrite($handle, $data);
            }
            fclose($handle);
            $handle = null;
            $mime   = $this->sniff($path, $imagesOnly);
        } catch (UnsafeRemoteFileException $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($path);

            throw $exception;
        } catch (Throwable) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($path);

            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::FAILED);
        }

        return new FetchedFile($path, $name, $size, $mime, (string) hash_file('sha256', $path));
    }

    private function sniff(string $path, bool $imagesOnly): string
    {
        $mime = strtolower((string) MimeTypes::getDefault()->guessMimeType($path));
        $head = '';
        $read = fopen($path, 'r');
        if (is_resource($read)) {
            $head = (string) fread($read, 1024);
            fclose($read);
        }
        if ($mime === '' || in_array($mime, self::DENIED_MIME_TYPES, true) || $this->looksLikeMarkup($head)
            || !in_array($mime, $this->allowedMimeTypes, true) || ($imagesOnly && !str_starts_with($mime, 'image/'))) {
            throw new UnsafeRemoteFileException(UnsafeRemoteFileException::TYPE_NOT_ALLOWED);
        }

        return $mime;
    }

    private function looksLikeMarkup(string $head): bool
    {
        return stripos($head, '<svg') !== false || stripos($head, '<!doctype svg') !== false
            || stripos($head, '<!doctype html') !== false || stripos($head, '<html') !== false || stripos($head, '<script') !== false;
    }

    private function looksLikeSvgName(string $name): bool
    {
        $lower = strtolower($name);

        return str_ends_with($lower, '.svg') || str_ends_with($lower, '.svgz');
    }

    /**
     * Base name only, control and bidi characters removed, at most 255 bytes; empty = "file".
     */
    private function safeName(string $name): string
    {
        $name = basename(rawurldecode(str_replace('\\', '/', $name)));
        $name = (string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = trim(mb_strcut($name, 0, 255, 'UTF-8'));

        return in_array($name, ['', '.', '..'], true) ? 'file' : $name;
    }

    /**
     * An absolute https URL for a redirect target (relative targets resolve against the current URL).
     */
    private function resolve(string $current, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = (array) parse_url($current);
        $base  = 'https://' . ($parts['host'] ?? '');
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $base . $location;
        }
        $path = (string) ($parts['path'] ?? '/');

        return $base . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }
}
