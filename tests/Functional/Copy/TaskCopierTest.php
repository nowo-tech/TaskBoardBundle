<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Copy;

use DateTimeImmutable;
use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Copy\TaskCopyPreview;
use Nowo\TaskBoardBundle\Copy\TaskCopyResult;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function sort;

/**
 * Specs 215 (FR-003 – FR-005) and 217 (FR-017 – FR-020): what a copy carries, what it never carries, and its refusals.
 */
final class TaskCopierTest extends CopyTestCase
{
    public function testACopyCarriesItsFieldsAndNeverTheRest(): void
    {
        $board    = $this->board(columns: ['To do', 'Doing', 'Done']);
        [$source] = $this->richTask($board);
        $this->clear();
        $source = $this->reload($source);

        $result = $this->copier()->copy($source, new TaskCopyOptions(title: 'Monthly invoices (copy)'), $this->owner);
        $this->clear();
        $copy = $this->reload($result->task);

        // Copied.
        self::assertSame('Monthly invoices (copy)', $copy->getTitle());
        self::assertSame('Send them all', $copy->getDescription());
        self::assertSame(TaskPriority::High, $copy->getPriority());
        self::assertSame(['billing', 'monthly'], $copy->getTags());
        self::assertSame('#aa0000', $copy->getColor());
        self::assertSame(90, $copy->getEstimatedMinutes());
        self::assertSame('2026-11-01', $copy->getStartAt()?->format('Y-m-d'));
        self::assertSame('2026-11-03', $copy->getDueAt()?->format('Y-m-d'));
        self::assertSame('Doing', $copy->getColumn()?->getName());
        self::assertSame(['Checklist'], array_values(array_map(static fn (TaskDocument $document): string => $document->getTitle(), $copy->getDocuments()->toArray())), 'the sync Fields document is sync data');
        self::assertSame([TaskMemberRole::Assignee], array_values(array_map(static fn (TaskMember $member): TaskMemberRole => $member->getMemberRole(), $copy->getMembers()->toArray())));

        // Never copied, one assertion per item (FR-005, FR-020).
        self::assertSame(0, $this->em->getRepository(TaskComment::class)->count(['task' => $copy->getId()]), 'comments');
        self::assertCount(0, $copy->getTimeEntries(), 'manual time entries');
        self::assertSame(0, $copy->getTotalTimeSeconds(), 'aggregated TimeTrack time');
        self::assertCount(0, $copy->getOutgoingDependencies(), 'dependencies');
        self::assertCount(0, $copy->getIncomingDependencies(), 'dependencies');
        self::assertCount(0, $copy->getLinks(), 'links, re-import keys included');
        self::assertNull($copy->getCompletedAt(), 'completion');
        self::assertNull($copy->getArchivedAt(), 'archive state');
        self::assertNull($copy->getNumber(), 'number (task numbers off)');
        $types = array_map(static fn (TaskChangeHistory $entry): string => $entry->getChangeType()->value, $copy->getChangeHistory()->toArray());
        sort($types);
        self::assertSame(['created', 'duplicated', 'member_added'], $types, 'history: new rows, not the source\'s (same second on MySQL: any order)');
        $duplicated = $this->historyRow($copy, TaskChangeType::Duplicated);
        self::assertSame('Monthly invoices', $duplicated->getOldValue());
        self::assertSame('task:' . $source->getId(), $duplicated->getContext());

        // The tree: the open subtask and its subtask, open, at their depth; the archived one left out.
        $children = $copy->getSubtasks()->toArray();
        self::assertCount(1, $children);
        $child = array_values($children)[0];
        self::assertSame('Collect receipts', $child->getTitle());
        self::assertFalse($child->isCompleted());
        self::assertSame('Doing', $child->getColumn()?->getName(), 'a done subtask lands open, in its own column');
        self::assertSame('Scan', array_values($child->getSubtasks()->toArray())[0]->getTitle());
        self::assertCount(3, $result->tasks);

        // The source keeps everything.
        $source = $this->reload($source);
        self::assertCount(1, $source->getTimeEntries());
        self::assertSame(3600, $source->getTotalTimeSeconds());
        self::assertSame(1, $this->em->getRepository(TaskComment::class)->count(['task' => $source->getId()]));
        self::assertSame(1, $this->em->getRepository(TaskTimeEntry::class)->count([]));

        // The UI's events: one TASK_CREATED per task, TASK_MEMBER_ADDED for the member.
        self::assertSame(3, count(array_filter($this->eventNames(), static fn (string $name): bool => $name === 'task_created')));
        self::assertContains('task_member_added', $this->eventNames());
    }

    public function testAFailedCopyDispatchesNoEvent(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Parent');
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            public int $calls = 0;

            public function onFlush(): void
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('database down');
                }
            }
        });

        try {
            $this->copier()->copy($task, new TaskCopyOptions(), $this->owner);
            self::fail('The failure reaches the caller.');
        } catch (RuntimeException $exception) {
            self::assertSame('database down', $exception->getMessage());
        }
        self::assertNotContains('task_created', $this->eventNames(), 'audit IC-I3: no listener acts on a rolled-back copy');
    }

    public function testAFailingTaskCreatedListenerDoesNotFailACommittedCopy(): void
    {
        $board      = $this->board();
        $task       = $this->task($board, 'Parent');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener('nowo_task_board.task_created', static function (): never {
            throw new RuntimeException('host listener');
        });

        // Audit JY-I6: the copy is committed when TASK_CREATED goes out; the host's failure is logged, not reported.
        $result = $this->copier()->copy($task, new TaskCopyOptions(), $this->owner);

        self::assertSame('Parent', $result->task->getTitle());
        self::assertContains('task_created', $this->eventNames(), 'the held event is still sent');
        $this->clear();
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]), 'one copy, saved once');
    }

    public function testOptionsLeaveOutSubtasksDatesAndMembers(): void
    {
        $board    = $this->board();
        [$source] = $this->richTask($board);
        $this->clear();

        $result = $this->copier()->copy($this->reload($source), new TaskCopyOptions(subtasks: false, dates: false, members: false), $this->owner);

        self::assertCount(1, $result->tasks);
        self::assertSame('Monthly invoices', $result->task->getTitle());
        self::assertNull($result->task->getStartAt());
        self::assertNull($result->task->getDueAt());
        self::assertCount(0, $result->task->getMembers());
    }

    public function testAPersonNoLongerAssignableIsLeftOutAndTheHistorySaysSo(): void
    {
        self::$taskBoardConfig = ['assignee_provider' => 'app.assignee_provider'];
        self::ensureKernelShutdown();
        $this->setUp();
        $source = $this->board();
        $target = $this->board('Other', creator: $this->user('ana@example.com'));
        $task   = $this->task($source, 'Water the plants');
        $task->addMember(new TaskMember($task, $this->owner, TaskMemberRole::Assignee));
        $this->em->flush();

        $preview = $this->copier()->preview($task, new TaskCopyOptions(targetBoard: $target));
        self::assertSame(['owner@example.com'], $preview->membersNotCopied);
        self::assertSame(0, $preview->membersCopied);

        $result = $this->copier()->copy($task, new TaskCopyOptions(targetBoard: $target), $this->owner);

        self::assertSame(['owner@example.com'], $result->membersNotCopied);
        self::assertCount(0, $result->task->getMembers());
        $types = array_map(static fn (TaskChangeHistory $entry): TaskChangeType => $entry->getChangeType(), $result->task->getChangeHistory()->toArray());
        self::assertContains(TaskChangeType::MemberNotCopied, $types);
        self::assertSame($target->getId(), $result->task->getBoard()->getId());
        self::assertNull($result->task->getParent());
    }

    public function testASubtaskCopiedOnItsBoardStaysUnderItsParentAndOnAnotherBoardIsTopLevel(): void
    {
        $board  = $this->board(columns: ['To do', 'Review', 'Done']);
        $other  = $this->board('Other', ['Backlog', 'review ', 'Done']);
        $parent = $this->task($board, 'Parent');
        $child  = $this->task($board, 'Child', 1, $parent);

        $same = $this->copier()->copy($child, new TaskCopyOptions(), $this->owner);
        self::assertSame($parent->getId(), $same->task->getParent()?->getId());

        $moved = $this->copier()->copy($child, new TaskCopyOptions(targetBoard: $other), $this->owner);
        self::assertNull($moved->task->getParent());
        self::assertSame('review ', $moved->task->getColumn()?->getName(), 'same column name after normalisation');

        $done = $this->task($board, 'Shipped', 2);
        $done->markCompleted();
        $this->em->flush();
        $open = $this->copier()->copy($done, new TaskCopyOptions(targetBoard: $other), $this->owner);
        self::assertSame('Backlog', $open->task->getColumn()?->getName(), 'never the done column for an open copy');
        self::assertFalse($open->task->isCompleted());

        $chosen = $this->copier()->copy($done, new TaskCopyOptions(targetBoard: $other, columnId: $other->getColumns()->get(1)?->getId()), $this->owner);
        self::assertSame('review ', $chosen->task->getColumn()?->getName());
        $last = $this->copier()->copy($done, new TaskCopyOptions(targetBoard: $other, columnId: $other->getColumns()->get(2)?->getId()), $this->owner);
        self::assertSame('Backlog', $last->task->getColumn()?->getName(), 'a chosen done column is ignored');
    }

    public function testRescheduleMovesTheCopyAndShiftsItsSubtasks(): void
    {
        $board  = $this->board();
        $source = $this->task($board, 'Monthly');
        $source->setStartAt(new DateTimeImmutable('2026-10-30'))->setDueAt(new DateTimeImmutable('2026-11-01'));
        $child = $this->task($board, 'Step', 0, $source);
        $child->setStartAt(new DateTimeImmutable('2026-10-31 09:30'))->setDueAt(new DateTimeImmutable('2026-10-31'));
        $this->em->flush();
        $this->clear();

        $result = $this->copier()->copy($this->reload($source), new TaskCopyOptions(reschedule: true, startAt: new DateTimeImmutable('2026-11-29'), dueAt: new DateTimeImmutable('2026-12-01'), historyContext: 'recurring'), $this->owner);

        self::assertSame('2026-11-29', $result->task->getStartAt()?->format('Y-m-d'));
        self::assertSame('2026-12-01', $result->task->getDueAt()?->format('Y-m-d'));
        self::assertSame('2026-11-30 09:30', $result->tasks[1]->getStartAt()?->format('Y-m-d H:i'));
        self::assertSame('2026-11-30', $result->tasks[1]->getDueAt()?->format('Y-m-d'));
        self::assertStringEndsWith(' recurring', (string) $this->historyRow($result->task, TaskChangeType::Duplicated)->getContext());

        $back = $this->copier()->copy($this->reload($source), new TaskCopyOptions(reschedule: true, dueAt: new DateTimeImmutable('2026-10-25')), $this->owner);
        self::assertSame('2026-10-24', $back->tasks[1]->getStartAt()?->format('Y-m-d'));

        $undated = $this->task($this->reload($source)->getBoard(), 'Undated');
        $none    = $this->copier()->copy($undated, new TaskCopyOptions(reschedule: true, dueAt: new DateTimeImmutable('2026-12-01')), $this->owner);
        self::assertSame('2026-12-01', $none->task->getDueAt()?->format('Y-m-d'));
    }

    public function testPreviewCountsWhatDoesNotCarryOverAndItsFingerprintFollowsTheFacts(): void
    {
        $board                  = $this->board();
        [$source, , , , $other] = $this->richTask($board);
        $this->clear();
        $source = $this->reload($source);

        $preview = $this->copier()->preview($source);
        self::assertSame(3, $preview->tasks);
        self::assertSame(2, $preview->subtasks());
        self::assertSame(1, $preview->comments);
        self::assertSame(1, $preview->timeEntries);
        self::assertSame(3600 + 30 * 60, $preview->trackedSeconds);
        self::assertSame(1, $preview->dependencies);
        self::assertSame(2, $preview->links);
        self::assertFalse($preview->synced);
        self::assertSame(1, $preview->membersCopied);
        self::assertSame($board->getId(), $preview->targetBoard->getId());
        self::assertSame($preview->fingerprint, $this->copier()->preview($source)->fingerprint);

        $this->em->persist(new TaskComment($source, $this->owner, 'One more'));
        $this->em->flush();
        self::assertNotSame($preview->fingerprint, $this->copier()->preview($source)->fingerprint);

        try {
            $this->copier()->copy($source, new TaskCopyOptions(), $this->owner, $preview->fingerprint);
            self::fail('A stale preview must be refused.');
        } catch (TaskCopyRefusedException $exception) {
            self::assertSame('stale', $exception->reason);
            self::assertNull($exception->hostMessage());
        }
        self::assertSame(2, count($this->em->getRepository(Task::class)->findBy(['title' => ['Monthly invoices', 'Other']])), 'nothing written');
        self::assertInstanceOf(Task::class, $other);
    }

    public function testRefusalsWriteNothing(): void
    {
        $board  = $this->board();
        $target = $this->board('Archived');
        $target->archive();
        $task = $this->task($board, 'Parent');
        $this->task($board, 'Child', 0, $task);
        $this->em->flush();
        $this->clear();
        $task = $this->reload($task);

        $this->assertRefused('board_archived', fn (): TaskCopyResult => $this->copier()->copy($task, new TaskCopyOptions(targetBoard: $this->em->find(TaskBoard::class, $target->getId())), $this->owner));

        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE];
        $refusal             = $this->assertRefused('quota', fn (): TaskCopyResult => $this->copier()->copy($task, new TaskCopyOptions(), $this->owner));
        self::assertSame('Your plan is full.', $refusal->hostMessage());
        self::assertSame(2, $this->quota()->asked[0]->count, 'the copy and its subtask');
        self::assertSame(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, $this->quota()->asked[0]->action);
        self::assertSame(2, $this->em->getRepository(Task::class)->count([]));
    }

    public function testASubtreeOverTheCapIsRefusedWithItsCount(): void
    {
        self::$taskBoardConfig = ['move' => ['max_subtasks' => 1]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        $task  = $this->task($board, 'Parent');
        $this->task($board, 'A', 0, $task);
        $this->task($board, 'B', 0, $task);
        $this->clear();

        $refusal = $this->assertRefused('subtree_too_large', fn (): TaskCopyPreview => $this->copier()->preview($this->reload($task)));
        self::assertSame(['%count%' => 2, '%max%' => 1], $refusal->params);
        $this->copier()->copy($this->reload($task), new TaskCopyOptions(subtasks: false), $this->owner);
        self::assertSame(4, $this->em->getRepository(Task::class)->count([]));
    }

    private function historyRow(Task $task, TaskChangeType $type): TaskChangeHistory
    {
        foreach ($task->getChangeHistory() as $entry) {
            if ($entry->getChangeType() === $type) {
                return $entry;
            }
        }
        self::fail('No history row ' . $type->value);
    }
}
