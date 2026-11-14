<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Tree;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Tree\TaskReparenter;
use Nowo\TaskBoardBundle\Tree\TaskReparentRefusedException;
use Nowo\TaskBoardBundle\Tree\TaskReparentResult;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Beacon spec 234, T004: the domain rules of a parent change, framework-free, over a plain-PHP store.
 */
final class TaskReparenterTest extends TestCase
{
    private InMemoryTaskTreeStore $store;

    private TaskBoard $board;

    private stdClass $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskTreeStore();
        $this->actor = new stdClass();
        $this->board = new TaskBoard('Roadmap', 'roadmap', $this->actor);
    }

    public function testAttachRecordsOneHistoryRowWithTheProvenanceAndTheEvent(): void
    {
        $parent = $this->task('Epic');
        $task   = $this->task('Story');
        $child  = $this->task('Sub', $task);

        $result = $this->reparenter(new WriteProvenance(WriteChannel::Mcp, 'agent'))->reparent($task->getId(), $parent->getId(), $this->actor);

        self::assertEquals(new TaskReparentResult($task->getId(), $this->board->getId(), null, $parent->getId(), true), $result);
        self::assertSame($parent, $task->getParent());
        self::assertSame($task, $child->getParent());
        self::assertSame([$task->getId()], $this->store->saved);
        self::assertSame([[$task->getId(), null]], $this->store->events);
        self::assertSame(1, $this->store->transactions);
        $rows = $task->getChangeHistory()->toArray();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame([TaskChangeType::Parent, null, 'Epic', 'task:' . $parent->getId(), WriteChannel::Mcp, 'agent'], [$row->getChangeType(), $row->getOldValue(), $row->getNewValue(), $row->getContext(), $row->getChannel(), $row->getActorLabel()]);
        self::assertSame('agent', $task->getActorLabel(), 'the last write of the task');
    }

    public function testDetach(): void
    {
        $parent = $this->task('Epic');
        $task   = $this->task('Story', $parent);

        $result = $this->reparenter()->reparent($task->getId(), null, $this->actor);

        self::assertTrue($result->changed);
        self::assertSame([$parent->getId(), null], [$result->previousParentId, $result->parentId]);
        self::assertNull($task->getParent());
        self::assertFalse($parent->getSubtasks()->contains($task));
        self::assertSame([[$task->getId(), $parent->getId()]], $this->store->events);
    }

    public function testTheSameParentWritesNothing(): void
    {
        $parent = $this->task('Epic');
        $task   = $this->task('Story', $parent);

        self::assertFalse($this->reparenter()->reparent($task->getId(), $parent->getId(), $this->actor)->changed);
        self::assertFalse($this->reparenter()->reparent($parent->getId(), null, $this->actor)->changed);
        self::assertSame([], $this->store->saved);
        self::assertCount(0, $task->getChangeHistory());
    }

    public function testRefusals(): void
    {
        $task       = $this->task('Story');
        $child      = $this->task('Sub', $task);
        $grandChild = $this->task('Sub-sub', $child);
        $elsewhere  = new Task(new TaskBoard('Other', 'other', $this->actor), 'Elsewhere', $this->actor);
        $this->store->add($elsewhere);

        $this->assertRefused(TaskReparentRefusedException::SELF, $task->getId(), $task->getId());
        $this->assertRefused(TaskReparentRefusedException::DESCENDANT, $task->getId(), $child->getId());
        $this->assertRefused(TaskReparentRefusedException::DESCENDANT, $task->getId(), $grandChild->getId());
        $this->assertRefused(TaskReparentRefusedException::OTHER_BOARD, $task->getId(), $elsewhere->getId());
        $this->assertRefused(TaskReparentRefusedException::NOT_FOUND, 'missing', null);
        $this->assertRefused(TaskReparentRefusedException::NOT_FOUND, $task->getId(), 'missing');
        self::assertSame([], $this->store->saved);
        self::assertNull($task->getParent());
    }

    public function testTheCheckWalksTheCommittedRowsLockedFromTheTaskUp(): void
    {
        $task   = $this->task('Story');
        $middle = $this->task('Middle');
        $top    = $this->task('Top');
        // Committed by another process: top under the task, middle under top. The objects still say otherwise.
        $this->store->rows = [$top->getId() => $task->getId(), $middle->getId() => $top->getId()];

        $this->assertRefused(TaskReparentRefusedException::DESCENDANT, $task->getId(), $middle->getId());
        self::assertSame([$task->getId(), $middle->getId(), $top->getId()], $this->store->locked, 'the task first, then the path up');
    }

    public function testALoopAlreadyInTheDataIsRefusedInsteadOfWalkedForever(): void
    {
        $task              = $this->task('Story');
        $a                 = $this->task('A');
        $b                 = $this->task('B');
        $this->store->rows = [$a->getId() => $b->getId(), $b->getId() => $a->getId()];

        $this->assertRefused(TaskReparentRefusedException::DESCENDANT, $task->getId(), $a->getId());
    }

    public function testAMissingAncestorEndsTheWalk(): void
    {
        $task              = $this->task('Story');
        $parent            = $this->task('Epic');
        $this->store->rows = [$parent->getId() => 'gone'];

        self::assertTrue($this->reparenter()->reparent($task->getId(), $parent->getId(), $this->actor)->changed);
    }

    public function testRefusalMessages(): void
    {
        foreach (['notFound', 'otherBoard', 'self', 'descendant', 'conflict'] as $factory) {
            self::assertNotSame('', TaskReparentRefusedException::$factory()->getMessage());
        }
        self::assertSame(TaskReparentRefusedException::CONFLICT, TaskReparentRefusedException::conflict()->reason);
    }

    private function assertRefused(string $reason, string $taskId, ?string $parentId): void
    {
        try {
            $this->reparenter()->reparent($taskId, $parentId, $this->actor);
            self::fail('Refused expected: ' . $reason);
        } catch (TaskReparentRefusedException $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function task(string $title, ?Task $parent = null): Task
    {
        $task = new Task($this->board, $title, $this->actor, parent: $parent);
        $this->store->add($task);

        return $task;
    }

    private function reparenter(?WriteProvenance $provenance = null): TaskReparenter
    {
        return new TaskReparenter($this->store, new TaskChangeRecorder(null, new class($provenance) implements WriteProvenanceProviderInterface {
            public function __construct(private readonly ?WriteProvenance $provenance)
            {
            }

            public function current(): ?WriteProvenance
            {
                return $this->provenance;
            }
        }));
    }
}
