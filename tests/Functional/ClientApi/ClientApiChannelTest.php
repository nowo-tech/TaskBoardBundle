<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): a write through the client API is recorded with the `app` channel (the client
 * API of a first-party app), without a provenance provider.
 */
final class ClientApiChannelTest extends ClientApiTestCase
{
    public function testAClientApiWriteIsRecordedWithTheAppChannel(): void
    {
        $board = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->call('POST', '/tasks', ['boardId' => $board->getId(), 'title' => 'From the desktop'], ['HTTP_IDEMPOTENCY_KEY' => 'channel']);
        self::assertResponseStatusCodeSame(201);

        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'From the desktop']);
        self::assertInstanceOf(Task::class, $task);
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => TaskChangeType::Created]);
        self::assertInstanceOf(TaskChangeHistory::class, $row);
        self::assertSame(WriteChannel::App, $row->getChannel());
        self::assertSame(WriteChannel::App, $task->getChannel());
    }
}
