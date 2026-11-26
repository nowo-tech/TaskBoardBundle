<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

/**
 * Since 3.4.0: without the optional ports, deny by default — a source is not a field, a reference and an image are not
 * visible, a host-object link is not visible.
 */
final class ClientApiWithoutOptionalPortsTest extends ClientApiTestCase
{
    protected const CLIENT_CONFIG = ['principal_resolver' => 'app.client_host', 'context_policy' => 'app.client_host'];

    public function testDenyByDefault(): void
    {
        $board = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->call('POST', '/tasks', ['boardId' => $board->getId(), 'title' => 'From chat', 'description' => '#[t](issue:1) ![i](beacon-file:f1)', 'source' => ['type' => 'chat_message', 'messageUuid' => 'm']], ['HTTP_IDEMPOTENCY_KEY' => 'src']);
        $this->assertError(400, 'invalid_field');
        self::assertSame('source', $this->json()['field']);

        $this->call('POST', '/tasks', ['boardId' => $board->getId(), 'title' => 'Plain', 'description' => '#[t](issue:1) ![i](beacon-file:f1)'], ['HTTP_IDEMPOTENCY_KEY' => 'plain']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], ['type' => 'text', 'text' => ' '], ['type' => 'image', 'visible' => false]], $this->json()['task']['description']['blocks'][0]['tokens']);
    }
}
