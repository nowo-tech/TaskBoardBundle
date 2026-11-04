<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Sync\Exception\UnsafeRemoteFileException;
use Nowo\TaskBoardBundle\Sync\File\FetchedFile;
use Nowo\TaskBoardBundle\Sync\File\HostAllowlist;
use Nowo\TaskBoardBundle\Sync\File\NullUrlGuard;
use Nowo\TaskBoardBundle\Sync\File\OutboundUrlGuardAdapter;
use Nowo\TaskBoardBundle\Sync\File\RemoteFileFetcher;
use Nowo\TaskBoardBundle\Sync\File\UrlGuardInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function file_get_contents;
use function str_repeat;
use function strlen;

/**
 * Since 1.23 (contract §5.2, Beacon SC-004): the SSRF matrix of the attachment fetcher.
 */
final class RemoteFileFetcherTest extends TestCase
{
    private const HOSTS = ['*.clickup-attachments.com', 'attachments.clickup.com'];

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $requests = [];

    public function testAnAllowedImageIsFetchedSniffedAndHashed(): void
    {
        $file = $this->fetcher([new MockResponse(self::PNG, ['response_headers' => ['content-type' => 'text/html']])])
            ->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a/b.png', "../evil\u{202E}gnp.png", null, 1000, true);

        self::assertSame('image/png', $file->mime, 'the declared type is ignored');
        self::assertSame('evilgnp.png', $file->name);
        self::assertSame(strlen(self::PNG), $file->size);
        self::assertSame(hash('sha256', self::PNG), $file->sha256);
        self::assertSame(self::PNG, (string) file_get_contents($file->path));
        $stream = $file->stream();
        self::assertSame(self::PNG, stream_get_contents($stream));
        fclose($stream);
        self::assertSame(0, $this->requests[0][1]['max_redirects']);
        self::assertArrayNotHasKey('authorization', array_change_key_case((array) ($this->requests[0][1]['normalized_headers'] ?? [])), 'no credential to attachment hosts');
        $file->delete();
        $file->delete();
        $this->expectException(RuntimeException::class);
        $file->stream();
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'plain http' => ['http://t1.clickup-attachments.com/a.png', UnsafeRemoteFileException::INVALID_URL];
        yield 'user info' => ['https://api.clickup.com@evil.test/a.png', UnsafeRemoteFileException::INVALID_URL];
        yield 'other port' => ['https://t1.clickup-attachments.com:8443/a.png', UnsafeRemoteFileException::INVALID_URL];
        yield 'ip literal' => ['https://169.254.169.254/latest/meta-data', UnsafeRemoteFileException::INVALID_URL];
        yield 'ipv6 literal' => ['https://[::1]/a.png', UnsafeRemoteFileException::INVALID_URL];
        yield 'not a url' => ['javascript:alert(1)', UnsafeRemoteFileException::INVALID_URL];
        yield 'off the allowlist' => ['https://evil.test/a.png', UnsafeRemoteFileException::HOST_NOT_ALLOWED];
        yield 'suffix trick' => ['https://evilclickup-attachments.com/a.png', UnsafeRemoteFileException::HOST_NOT_ALLOWED];
        yield 'bare suffix' => ['https://clickup-attachments.com/a.png', UnsafeRemoteFileException::HOST_NOT_ALLOWED];
        yield 'unicode host' => ['https://tést.clickup-attachments.com/a.png', UnsafeRemoteFileException::HOST_NOT_ALLOWED];
    }

    #[DataProvider('refusedUrls')]
    public function testUnsafeUrlsAreRefusedBeforeAnyRequest(string $url, string $reason): void
    {
        $this->assertRefused($reason, fn (): FetchedFile => $this->fetcher([])->fetch(self::HOSTS, $url, 'a.png', null, 1000));
        self::assertSame([], $this->requests);
    }

    public function testRedirectsAreFollowedOnlyOnTheAllowlist(): void
    {
        $hop = static fn (string $to): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $to]]);

        $file = $this->fetcher([$hop('/b.png'), $hop('//attachments.clickup.com/c.png'), $hop('d.png'), new MockResponse(self::PNG)])
            ->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/x/a.png', 'a.png', null, 1000);
        self::assertSame('https://attachments.clickup.com/d.png', $this->requests[3][0]);
        $file->delete();

        $this->requests = [];
        $this->assertRefused(UnsafeRemoteFileException::INVALID_URL, fn (): FetchedFile => $this->fetcher([$hop('http://169.254.169.254/latest/meta-data')])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        self::assertCount(1, $this->requests, 'the metadata address is never requested');
        $this->assertRefused(UnsafeRemoteFileException::HOST_NOT_ALLOWED, fn (): FetchedFile => $this->fetcher([$hop('https://evil.test/a.png')])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::TOO_MANY_HOPS, fn (): FetchedFile => $this->fetcher([$hop('/1'), $hop('/2'), $hop('/3'), $hop('/4')])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::TOO_MANY_HOPS, fn (): FetchedFile => $this->fetcher([new MockResponse('', ['http_code' => 302])])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
    }

    public function testSizesAreCappedBeforeAndWhileDownloading(): void
    {
        $this->assertRefused(UnsafeRemoteFileException::TOO_LARGE, fn (): FetchedFile => $this->fetcher([], 10)->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', 11, 1000));
        $this->assertRefused(UnsafeRemoteFileException::RUN_BUDGET, fn (): FetchedFile => $this->fetcher([], 100)->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', 50, 20));
        // Content-Length over the cap: refused before the body is read.
        $this->assertRefused(UnsafeRemoteFileException::TOO_LARGE, fn (): FetchedFile => $this->fetcher([new MockResponse(str_repeat('a', 200), ['response_headers' => ['content-length' => '200']])], 100)->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::RUN_BUDGET, fn (): FetchedFile => $this->fetcher([new MockResponse(str_repeat('a', 60), ['response_headers' => ['content-length' => '60']])], 100)->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 30));
        // No (or a chunked) Content-Length: still bounded while streaming.
        $this->assertRefused(UnsafeRemoteFileException::TOO_LARGE, fn (): FetchedFile => $this->fetcher([new MockResponse([str_repeat('a', 60), str_repeat('b', 60)])], 100)->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::RUN_BUDGET, fn (): FetchedFile => $this->fetcher([new MockResponse([str_repeat('a', 60)])], 100)->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 30));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2?: bool}>
     */
    public static function refusedContents(): iterable
    {
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.png'];
        yield 'svg doctype' => ["<?xml version=\"1.0\"?>\n<!DOCTYPE svg><svg/>", 'a.png'];
        yield 'html' => ['<!doctype html><html><script>alert(1)</script></html>', 'a.png'];
        yield 'script only' => ['hello <script>alert(1)</script>', 'a.txt'];
        yield 'svg name' => [self::PNG, 'logo.SVG'];
        yield 'svgz name' => [self::PNG, 'logo.svgz'];
        yield 'not allowed' => ["\x7FELF\x02\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00", 'a.bin'];
        yield 'empty' => ['', 'a.png'];
        yield 'text as an inline image' => ['plain text', 'a.png', true];
    }

    #[DataProvider('refusedContents')]
    public function testDangerousOrUnexpectedTypesAreRefusedBySniffing(string $body, string $name, bool $imagesOnly = false): void
    {
        $this->assertRefused(UnsafeRemoteFileException::TYPE_NOT_ALLOWED, fn (): FetchedFile => $this->fetcher([new MockResponse($body)])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a', $name, null, 1000, $imagesOnly));
    }

    public function testFailuresAuthAndTransportErrors(): void
    {
        $this->assertRefused(UnsafeRemoteFileException::AUTH_REQUIRED, fn (): FetchedFile => $this->fetcher([new MockResponse('', ['http_code' => 403])])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::FAILED, fn (): FetchedFile => $this->fetcher([new MockResponse('', ['http_code' => 500])])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::FAILED, fn (): FetchedFile => $this->fetcher([new MockResponse('', ['error' => 'host unreachable'])])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $this->assertRefused(UnsafeRemoteFileException::FAILED, fn (): FetchedFile => $this->fetcher([new MockResponse([new TransportException('cut')])])->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        $throwing = new RemoteFileFetcher(new MockHttpClient(static function (): never {
            throw new TransportException('no');
        }));
        $this->assertRefused(UnsafeRemoteFileException::FAILED, static fn (): FetchedFile => $throwing->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        self::assertSame('The remote file was refused: download_failed.', (new UnsafeRemoteFileException(UnsafeRemoteFileException::FAILED))->getMessage());
    }

    public function testPrivateNetworksAreRefusedAfterResolution(): void
    {
        // NoPrivateNetworkHttpClient (the DI wraps the fetcher's client in it) refuses a host that resolves to a
        // private address — here a mock answering from 127.0.0.1.
        $client = new NoPrivateNetworkHttpClient(new MockHttpClient([new MockResponse(self::PNG, ['primary_ip' => '127.0.0.1'])]));
        $this->assertRefused(UnsafeRemoteFileException::FAILED, static fn (): FetchedFile => (new RemoteFileFetcher($client))->fetch(['*.example.test'], 'https://internal.example.test/a.png', 'a.png', null, 1000));
    }

    public function testTheUrlGuardIsAskedForEveryHop(): void
    {
        $guard = new class implements UrlGuardInterface {
            /** @var list<string> */
            public array $urls = [];

            public function optionsFor(string $url): array
            {
                $this->urls[] = $url;
                if (str_contains($url, 'blocked')) {
                    throw new UnsafeRemoteFileException(UnsafeRemoteFileException::HOST_NOT_ALLOWED);
                }

                return ['resolve' => ['t1.clickup-attachments.com' => '203.0.113.7']];
            }
        };
        $fetcher = new RemoteFileFetcher(new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = [$url, $options];

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://t2.clickup-attachments.com/blocked.png']]);
        }), urlGuard: $guard);
        $this->assertRefused(UnsafeRemoteFileException::HOST_NOT_ALLOWED, static fn (): FetchedFile => $fetcher->fetch(self::HOSTS, 'https://t1.clickup-attachments.com/a.png', 'a.png', null, 1000));
        self::assertCount(2, $guard->urls);
        self::assertSame(['t1.clickup-attachments.com' => '203.0.113.7'], $this->requests[0][1]['resolve']);
        self::assertSame([], (new NullUrlGuard())->optionsFor('https://x.test'));
    }

    public function testTheOutboundUrlGuardAdapter(): void
    {
        $adapter = new OutboundUrlGuardAdapter(new OutboundUrlGuard(resolveDns: false));
        self::assertIsArray($adapter->optionsFor('https://t1.clickup-attachments.com/a.png'));
        $this->assertRefused(UnsafeRemoteFileException::HOST_NOT_ALLOWED, static fn (): array => $adapter->optionsFor('https://169.254.169.254/'));
    }

    public function testTheHostAllowlist(): void
    {
        self::assertTrue(HostAllowlist::allows(self::HOSTS, 'T1.ClickUp-Attachments.com.'));
        self::assertTrue(HostAllowlist::allows(self::HOSTS, 'a.b.clickup-attachments.com'));
        self::assertTrue(HostAllowlist::allows(self::HOSTS, 'attachments.clickup.com'));
        // Audit EG-I9: a bare TLD wildcard, a lone wildcard or a single label never allows anything.
        foreach (['*.com', '*', '*.', 'localhost', '*.*.com', '*.com.'] as $pattern) {
            self::assertFalse(HostAllowlist::isValidPattern($pattern), $pattern);
        }
        self::assertFalse(HostAllowlist::allows(['*.com', 'localhost'], 'evil.com'));
        self::assertFalse(HostAllowlist::allows(['*.com', 'localhost'], 'localhost'));
        self::assertTrue(HostAllowlist::isValidPattern('*.example.com'));
        self::assertFalse(HostAllowlist::allows(self::HOSTS, 'xattachments.clickup.com'));
        self::assertFalse(HostAllowlist::allows(self::HOSTS, ''));
        self::assertFalse(HostAllowlist::allows([], 'attachments.clickup.com'));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function fetcher(array $responses, int $maxBytes = 1_000_000): RemoteFileFetcher
    {
        $this->requests = [];

        return new RemoteFileFetcher(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [$url, $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 404]);
        }), $maxBytes);
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('not refused: ' . $reason);
        } catch (UnsafeRemoteFileException $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertStringNotContainsString('http', $exception->getMessage(), 'no URL in the message');
        }
    }
}
