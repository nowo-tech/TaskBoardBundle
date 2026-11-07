<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiKernel;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

use function is_string;
use function json_decode;
use function json_encode;

/**
 * The bundle on {@see ApiKernel}, every hook scripted through {@see ApiTestHost}.
 */
abstract class ApiTestCase extends FunctionalTestCase
{
    /** A placeholder, in the host's format. */
    protected const TOKEN = 'bt_placeholder_token';

    /** @var array<string, mixed> */
    protected static array $api = [];

    /** @var array<string, mixed> */
    protected static array $hostConfig = [];

    protected static bool $withFirewall = true;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new ApiKernel(static::$api, static::$hostConfig, static::$withFirewall);
    }

    protected function setUp(): void
    {
        ApiTestHost::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        static::$api          = [];
        static::$hostConfig   = [];
        static::$withFirewall = true;
        ApiTestHost::reset();
        parent::tearDown();
    }

    protected function issueToken(TaskApiLevel $level = TaskApiLevel::Full, ?TaskApiWhere $where = null, string $email = 'owner@example.com', string $token = self::TOKEN): void
    {
        ApiTestHost::$tokens[$token] = ['email' => $email, 'level' => $level, 'where' => $where ?? TaskApiWhere::everything(), 'id' => $token];
    }

    /**
     * @param array<string, string> $server
     */
    protected function get(string $path, ?string $authorization = self::TOKEN, array $server = []): void
    {
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        $this->client->request('GET', $path, [], [], $server);
    }

    /**
     * A write: `$body` as JSON, or the raw string given.
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers HTTP_* server keys
     */
    protected function send(string $method, string $path, array|string|null $body = null, array $headers = [], string $token = self::TOKEN): void
    {
        $content = is_string($body) ? $body : ($body === null ? '' : (string) json_encode($body));
        $this->client->request($method, $path, [], [], $headers + ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'application/json'], $content);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function listOf(string $key): array
    {
        $list = $this->json()[$key] ?? null;
        self::assertIsArray($list);

        return array_values($list);
    }

    /**
     * @return list<string>
     */
    protected function idsOf(string $key): array
    {
        return array_map(static fn (array $item): string => (string) $item['id'], $this->listOf($key));
    }

    protected function assertRefused(int $status, string $ecode): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame($ecode, $this->json()['ECODE'] ?? null);
        self::assertSame(['err', 'ECODE'], array_keys($this->json()));
    }
}
