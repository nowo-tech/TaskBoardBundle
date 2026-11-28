<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\History;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteChannelScope;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\PathWriteProvenance;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedCorrelationIds;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

use function str_ends_with;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): every task history row keeps the correlation id of its unit of work (from the
 * host's provider, autowired) and the channel its entry point declared (the board pages `web`), read back from the
 * database; the host's provenance provider still wins; nothing leaks into the next request of the same kernel.
 */
final class WriteChannelAndCorrelationTest extends FunctionalTestCase
{
    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    protected function setUp(): void
    {
        ScriptedCorrelationIds::$current = null;
        PathWriteProvenance::reset();
        self::$taskBoardConfig = str_ends_with($this->name(), 'HostProviderWins') ? ['write_provenance_provider' => 'app.write_provenance'] : [];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        ScriptedCorrelationIds::$current = null;
        PathWriteProvenance::reset();
        parent::tearDown();
    }

    public function testAWebWriteKeepsTheWebChannelAndTheCorrelationId(): void
    {
        $task = $this->task($this->board(), 'Move me', 1);
        $this->login($this->owner);
        ScriptedCorrelationIds::$current = CorrelationId::fromString(self::TRACE);

        $this->client->request('POST', '/tools/task-board/task/' . $task->getId() . '/advance', ['_token' => PredictableCsrfTokenManager::value('task_board_task_advance'), 'action' => 'next']);
        self::assertResponseRedirects();

        $row = $this->row($task, TaskChangeType::Column);
        self::assertSame(WriteChannel::Web, $row->getChannel());
        self::assertNull($row->getActorLabel());
        self::assertSame(self::TRACE, $row->getCorrelationId());
        self::assertSame(WriteChannel::Web, $row->getTask()->getChannel(), 'the task\'s last write too');
        self::assertNull(self::getContainer()->get(WriteChannelScope::class)->current(), 'nothing outlives the request');
    }

    public function testAWriteOutsideAnyEntryPointHasNeitherChannelNorId(): void
    {
        $task = $this->task($this->board(), 'Plan');
        $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner);
        $this->em->flush();

        $row = $this->row($task, TaskChangeType::Priority);
        self::assertNull($row->getChannel());
        self::assertNull($row->getCorrelationId());
    }

    public function testAHostEntryPointDeclaresItsOwnChannel(): void
    {
        $task  = $this->task($this->board(), 'Plan');
        $scope = self::getContainer()->get(WriteChannelScope::class);
        self::assertInstanceOf(WriteChannelScope::class, $scope);
        $scope->run(WriteChannel::Mcp, fn () => $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner));
        $this->em->flush();

        self::assertSame(WriteChannel::Mcp, $this->row($task, TaskChangeType::Priority)->getChannel());
    }

    public function testTheHostProviderWins(): void
    {
        $task = $this->task($this->board(), 'Move me', 1);
        $this->login($this->owner);
        PathWriteProvenance::$label = 'Agent';

        // The path decides for the host (`/tools/` is its web UI), with its label: the host's answer is kept whole.
        $this->client->request('POST', '/tools/task-board/task/' . $task->getId() . '/advance', ['_token' => PredictableCsrfTokenManager::value('task_board_task_advance'), 'action' => 'next']);
        $row = $this->row($task, TaskChangeType::Column);
        self::assertSame(WriteChannel::Web, $row->getChannel());
        self::assertNull($row->getActorLabel(), 'the host answered for its web UI without a label');

        PathWriteProvenance::$console = new WriteProvenance(WriteChannel::Mcp, 'Claude via MCP');
        $scope                        = self::getContainer()->get(WriteChannelScope::class);
        self::assertInstanceOf(WriteChannelScope::class, $scope);
        $task = $row->getTask();
        $scope->run(WriteChannel::Cli, fn () => $this->tasks()->updatePriority($task, TaskPriority::High, $this->owner));
        $this->em->flush();
        $row = $this->row($task, TaskChangeType::Priority);
        self::assertSame(WriteChannel::Mcp, $row->getChannel());
        self::assertSame('Claude via MCP', $row->getActorLabel());
    }

    private function row(Task $task, TaskChangeType $type): TaskChangeHistory
    {
        $this->em->clear();
        $row = $this->em->getRepository(TaskChangeHistory::class)->findOneBy(['task' => $task->getId(), 'changeType' => $type]);
        self::assertInstanceOf(TaskChangeHistory::class, $row);

        return $row;
    }

    private function tasks(): TaskManager
    {
        $manager = self::getContainer()->get(TaskManager::class);
        self::assertInstanceOf(TaskManager::class, $manager);

        return $manager;
    }
}
