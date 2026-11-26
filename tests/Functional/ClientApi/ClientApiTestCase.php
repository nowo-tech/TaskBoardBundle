<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\Tests\Functional\Api\ApiTestCase;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ClientApiTestHost;

use function is_string;
use function json_encode;

/**
 * Since 3.4.0: the client API (`/api/task-board/v1`) on the API test kernel, every client port scripted through
 * {@see ClientApiTestHost}. The owner holds a read + write device token in the `personal` context (tenant `tenant-a`).
 */
abstract class ClientApiTestCase extends ApiTestCase
{
    protected const CLIENT_TOKEN = 'desk_placeholder_token';

    protected const V1 = '/api/task-board/v1';

    /** The client API's configuration of the tests (merged over by a test that needs another). */
    protected const CLIENT_CONFIG = [
        'principal_resolver' => 'app.client_host',
        'context_policy'     => 'app.client_host',
        'reference_resolver' => 'app.client_host',
        'link_visibility'    => 'app.client_host',
        'file_resolver'      => 'app.client_host',
        'source_link'        => 'app.client_host',
        'file_scheme'        => 'beacon-file',
    ];

    protected function setUp(): void
    {
        ClientApiTestHost::reset();
        static::$hostConfig += ['client_api' => static::CLIENT_CONFIG];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b'];
        $this->clientToken();
    }

    protected function tearDown(): void
    {
        ClientApiTestHost::reset();
        parent::tearDown();
    }

    /**
     * @param list<string> $scopes
     * @param array<string, list<string>> $contexts
     */
    protected function clientToken(array $scopes = [TaskClientPrincipal::SCOPE_READ, TaskClientPrincipal::SCOPE_WRITE], string $email = 'owner@example.com', string $token = self::CLIENT_TOKEN, array $contexts = ['personal' => ['tenant-a'], 'org:acme' => ['tenant-b']], ?string $bound = null): void
    {
        ClientApiTestHost::$tokens[$token]   = ['email' => $email, 'scopes' => $scopes, 'id' => 'tok-' . $token, 'context' => $bound];
        ClientApiTestHost::$contexts[$email] = $contexts;
    }

    /**
     * @param array<string, string> $headers HTTP_* server keys
     */
    protected function call(string $method, string $path, mixed $body = null, array $headers = [], ?string $token = self::CLIENT_TOKEN, ?string $context = 'personal'): void
    {
        $server = $headers + ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($context !== null) {
            $server['HTTP_X_BEACON_CONTEXT'] = $context;
        }
        $content = $body === null ? '' : (is_string($body) ? $body : (string) json_encode($body));
        $this->client->request($method, self::V1 . $path, [], [], $server, $content);
    }

    protected function assertError(int $status, string $error): void
    {
        self::assertResponseStatusCodeSame($status, (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->json()['ok'] ?? null);
        self::assertSame($error, $this->json()['error'] ?? null);
    }
}
