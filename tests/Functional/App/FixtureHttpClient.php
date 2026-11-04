<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Since 1.23: the sync's HTTP client in the functional kernel — no network: answers from `$responder`, or a 1x1 PNG.
 */
final class FixtureHttpClient extends MockHttpClient
{
    public const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82";

    /** @var (callable(string, string, array<string, mixed>): ResponseInterface)|null */
    public static $responder;

    /** @var list<string> requested URLs */
    public static array $requests = [];

    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options): ResponseInterface {
            self::$requests[] = $url;

            return self::$responder !== null ? (self::$responder)($method, $url, $options) : new MockResponse(self::PNG, ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
        });
    }

    public static function resetFixtures(): void
    {
        self::$responder = null;
        self::$requests  = [];
    }
}
