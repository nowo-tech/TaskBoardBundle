<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_map;

final class TaskChangeRecorderTest extends TestCase
{
    public function testRecordStoresContext(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Work', new stdClass());
        $user  = new stdClass();

        (new TaskChangeRecorder())->record($task, $user, TaskChangeType::Title, 'A', 'B', 'ctx');

        self::assertSame('ctx', $task->getChangeHistory()->first()->getContext());
    }

    public function testEveryRowKeepsTheHostsCorrelationIdSince370(): void
    {
        $board       = new TaskBoard('Demo', 'demo', new stdClass());
        $task        = new Task($board, 'Work', new stdClass());
        $correlation = new class implements CorrelationIdProviderInterface {
            public ?CorrelationId $id = null;

            public function current(): ?CorrelationId
            {
                return $this->id;
            }

            public function currentOrGenerate(): CorrelationId
            {
                return $this->id ?? CorrelationId::generate();
            }
        };
        $recorder = new TaskChangeRecorder(correlation: $correlation);

        $recorder->record($task, new stdClass(), TaskChangeType::Title, 'A', 'B');
        $correlation->id = CorrelationId::fromString('4bf92f3577b34da6a3ce929d0e0e4736');
        $recorder->record($task, new stdClass(), TaskChangeType::Title, 'B', 'C');
        $recorder->recordAs($task, null, 'Guest', TaskChangeType::Title, 'C', 'D');
        (new TaskChangeRecorder(correlation: new NullCorrelationIdProvider()))->record($task, new stdClass(), TaskChangeType::Title, 'D', 'E');

        self::assertSame([null, '4bf92f3577b34da6a3ce929d0e0e4736', '4bf92f3577b34da6a3ce929d0e0e4736', null], array_map(static fn (TaskChangeHistory $row): ?string => $row->getCorrelationId(), $task->getChangeHistory()->toArray()));
        $row = $task->getChangeHistory()->get(1);
        self::assertInstanceOf(TaskChangeHistory::class, $row);
        $row->detachUser(true);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $row->getCorrelationId(), 'not personal data: kept by an erasure');
    }

    public function testRecordCreatedAddsHistoryEntry(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Ship feature', new stdClass());
        $user  = new stdClass();

        $recorder = new TaskChangeRecorder();
        $recorder->recordCreated($task, $user);

        self::assertCount(1, $task->getChangeHistory());
        $entry = $task->getChangeHistory()->first();
        self::assertSame(TaskChangeType::Created, $entry->getChangeType());
        self::assertSame('Ship feature', $entry->getNewValue());
        self::assertSame($user, $entry->getUser());
    }

    public function testRecordIfChangedSkipsUnchangedValues(): void
    {
        $board = new TaskBoard('Demo', 'demo', new stdClass());
        $task  = new Task($board, 'Ship feature', new stdClass(), priority: TaskPriority::Normal);
        $user  = new stdClass();

        $recorder = new TaskChangeRecorder();
        $recorder->recordIfChanged($task, $user, TaskChangeType::Priority, TaskPriority::Normal, TaskPriority::Normal);

        self::assertCount(0, $task->getChangeHistory());
    }

    public function testRecordIfChangedStoresColumnNames(): void
    {
        $board    = new TaskBoard('Demo', 'demo', new stdClass());
        $todo     = new BoardColumn($board, 'To do', 0);
        $progress = new BoardColumn($board, 'In progress', 1);
        $board->addColumn($todo)->addColumn($progress);

        $task = new Task($board, 'Ship feature', new stdClass(), column: $todo);
        $user = new stdClass();

        $recorder = new TaskChangeRecorder();
        $recorder->recordIfChanged($task, $user, TaskChangeType::Column, $todo, $progress);

        self::assertCount(1, $task->getChangeHistory());
        $entry = $task->getChangeHistory()->first();
        self::assertSame('To do', $entry->getOldValue());
        self::assertSame('In progress', $entry->getNewValue());
    }
}
