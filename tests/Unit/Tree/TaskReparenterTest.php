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
use Nowo\TaskBoardBundle\Tree\TaskReparentResult;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
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

        $this->assertRefused(TaskTreeRefusedException::SELF, $task->getId(), $task->getId());
        $this->assertRefused(TaskTreeRefusedException::DESCENDANT, $task->getId(), $child->getId());
        $this->assertRefused(TaskTreeRefusedException::DESCENDANT, $task->getId(), $grandChild->getId());
        $this->assertRefused(TaskTreeRefusedException::OTHER_BOARD, $task->getId(), $elsewhere->getId());
        $this->assertRefused(TaskTreeRefusedException::NOT_FOUND, 'missing', null);
        $this->assertRefused(TaskTreeRefusedException::NOT_FOUND, $task->getId(), 'missing');
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

        $this->assertRefused(TaskTreeRefusedException::DESCENDANT, $task->getId(), $middle->getId());
        self::assertSame([$task->getId(), $middle->getId(), $top->getId()], $this->store->locked, 'the task first, then the path up');
    }

    public function testALoopAlreadyInTheDataIsRefusedInsteadOfWalkedForever(): void
    {
        $task              = $this->task('Story');
        $a                 = $this->task('A');
        $b                 = $this->task('B');
        $this->store->rows = [$a->getId() => $b->getId(), $b->getId() => $a->getId()];

        $this->assertRefused(TaskTreeRefusedException::DESCENDANT, $task->getId(), $a->getId());
    }

    public function testAMissingAncestorEndsTheWalk(): void
    {
        $task              = $this->task('Story');
        $parent            = $this->task('Epic');
        $this->store->rows = [$parent->getId() => 'gone'];

        self::assertTrue($this->reparenter()->reparent($task->getId(), $parent->getId(), $this->actor)->changed);
    }

    public function testAMoveDeeperKeepsTheWholeSubtreeWithinTheLimit(): void
    {
        // max_subtask_depth 3: depths 0..3 allowed.
        $epic    = $this->task('Epic');
        $story   = $this->task('Story', $epic);              // depth 1
        $subtree = $this->task('Subtree');                   // depth 0, height 2
        $child   = $this->task('Child', $subtree);
        $this->task('Grandchild', $child);

        $this->assertRefused(TaskTreeRefusedException::TOO_DEEP, $subtree->getId(), $story->getId(), 3); // 2 + 2 = 4
        self::assertSame([[$subtree->getId(), 1]], $this->store->heights, 'the height walk is bounded by what is left');
        self::assertTrue($this->reparenter(limit: 3)->reparent($subtree->getId(), $epic->getId(), $this->actor)->changed); // 1 + 2 = 3
        $this->assertRefused(TaskTreeRefusedException::TOO_DEEP, $this->task('Leaf')->getId(), $this->chainOf(4)->getId(), 3);
    }

    public function testATreeSavedDeeperStaysMovableUpAndDetachable(): void
    {
        $deepest = $this->chainOf(5);                         // depth 4, past a limit of 2
        $middle  = $this->task('Middle', $this->chainOf(3));   // depth 3
        $this->task('Below', $middle);

        $this->assertRefused(TaskTreeRefusedException::TOO_DEEP, $middle->getId(), $deepest->getId(), 2);
        self::assertTrue($this->reparenter(limit: 2)->reparent($middle->getId(), $this->chainOf(2)->getId(), $this->actor)->changed, 'from depth 3 to 2: no deeper, allowed past the limit');
        $this->store->heights = [];
        self::assertTrue($this->reparenter(limit: 2)->reparent($deepest->getId(), null, $this->actor)->changed, 'detach');
        self::assertSame([], $this->store->heights, 'no height walk for a move that is no deeper');
    }

    public function testWithoutALimitThePathWalkStillStopsAtTheSafetyCap(): void
    {
        $deepest = $this->chainOf(TaskTreeLimits::SAFETY_CAP + 2);

        $this->assertRefused(TaskTreeRefusedException::TOO_DEEP, $this->task('Leaf')->getId(), $deepest->getId());
    }

    public function testATaskInALoopIsInfinitelyDeepSoAnyMoveIsNoDeeper(): void
    {
        $a                 = $this->task('A');
        $b                 = $this->task('B');
        $other             = $this->task('Other');
        $this->store->rows = [$a->getId() => $b->getId(), $b->getId() => $a->getId()];

        self::assertTrue($this->reparenter(limit: 1)->reparent($a->getId(), $other->getId(), $this->actor)->changed, 'moving it out repairs the loop');
        self::assertSame([], $this->store->heights);
    }

    public function testRefusalMessages(): void
    {
        foreach (['notFound', 'otherBoard', 'self', 'descendant', 'conflict', 'tooDeep'] as $factory) {
            self::assertNotSame('', TaskTreeRefusedException::$factory()->getMessage());
        }
        self::assertSame(TaskTreeRefusedException::CONFLICT, TaskTreeRefusedException::conflict()->reason);
    }

    private function assertRefused(string $reason, string $taskId, ?string $parentId, ?int $limit = null): void
    {
        try {
            $this->reparenter(limit: $limit)->reparent($taskId, $parentId, $this->actor);
            self::fail('Refused expected: ' . $reason);
        } catch (TaskTreeRefusedException $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function task(string $title, ?Task $parent = null): Task
    {
        $task = new Task($this->board, $title, $this->actor, parent: $parent);
        $parent?->getSubtasks()->add($task); // as Doctrine loads it
        $this->store->add($task);

        return $task;
    }

    /** The deepest task of a new chain of `$length` tasks (depth `$length - 1`). */
    private function chainOf(int $length): Task
    {
        $task = null;
        for ($i = 0; $i < $length; ++$i) {
            $task = $this->task('Level ' . $i, $task);
        }
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }

    private function reparenter(?WriteProvenance $provenance = null, ?int $limit = null): TaskReparenter
    {
        return new TaskReparenter($this->store, new TaskChangeRecorder(null, new class($provenance) implements WriteProvenanceProviderInterface {
            public function __construct(private readonly ?WriteProvenance $provenance)
            {
            }

            public function current(): ?WriteProvenance
            {
                return $this->provenance;
            }
        }), new TaskTreeLimits($limit));
    }
}
