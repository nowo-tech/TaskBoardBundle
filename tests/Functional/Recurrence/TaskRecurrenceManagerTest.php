<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Recurrence;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardChangedEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskOccurrence;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceBoardListener;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use stdClass;

use function array_map;

/**
 * Spec 215, FR-001 – FR-008, SC-001, SC-002, SC-005: series, idempotent occurrences, what they copy, pause, skip, end.
 */
final class TaskRecurrenceManagerTest extends CopyTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['recurrence' => ['enabled' => true]];
        parent::setUp();
    }

    public function testAnOccurrenceIsCreatedOnceWithItsDatesAndNoneOfTheSourcesComments(): void
    {
        $board    = $this->board(columns: ['To do', 'Doing', 'Done']);
        [$source] = $this->richTask($board);
        $this->clear();
        $source = $this->reload($source);

        $series = $this->manager()->create($source, $this->monthly(), $this->owner);
        self::assertSame('2026-12-03', $series->getNextDate()?->format('Y-m-d'));
        self::assertSame(['2026-12-03', '2027-01-03', '2027-02-03'], $this->days($this->manager()->upcoming($series)));
        self::assertSame('date:2026-12-03', $this->manager()->dueKey($series, new DateTimeImmutable('2026-12-02')), 'one day of lead');
        self::assertNull($this->manager()->dueKey($series, new DateTimeImmutable('2026-12-01')));
        self::assertSame([$series], $this->manager()->dueSeries(new DateTimeImmutable('2026-12-02')));
        self::assertSame([], $this->manager()->dueSeries(new DateTimeImmutable('2026-12-01')));

        self::assertNull($this->manager()->createOccurrence($series, 'date:2026-12-15'), 'not a date of the rule');

        // SC-001: three deliveries, one task.
        $copy = $this->manager()->createOccurrence($series, 'date:2026-12-03');
        self::assertInstanceOf(Task::class, $copy);
        self::assertNull($this->manager()->createOccurrence($series, 'date:2026-12-03'));
        self::assertNull($this->manager()->createOccurrence($series, 'date:2026-12-03'));
        self::assertSame(2, $this->em->getRepository(TaskOccurrence::class)->count([]), 'the first task and this one');

        self::assertSame('2026-12-01', $copy->getStartAt()?->format('Y-m-d'), 'the original two days between start and due');
        self::assertSame('2026-12-03', $copy->getDueAt()?->format('Y-m-d'));
        self::assertSame('Doing', $copy->getColumn()?->getName(), 'the column stored when the series was made');
        self::assertSame(0, $this->em->getRepository(TaskComment::class)->count(['task' => $copy->getId()]));
        self::assertSame(0, $copy->getTotalTimeSeconds());
        self::assertCount(0, $copy->getTimeEntries());
        self::assertSame('recurring', $this->row($copy, TaskChangeType::Created)?->getContext());
        self::assertSame($copy, $series->getLatestTask());
        self::assertSame(2, $series->getOccurrences());
        self::assertSame('2027-01-03', $series->getNextDate()?->format('Y-m-d'));
        self::assertSame($series, $this->manager()->seriesOf($copy));
        self::assertNull($this->manager()->seriesOf($source), 'past occurrences are independent tasks');
        self::assertSame([$copy->getId() => true], $this->manager()->recurringTaskIds([$board->getId()]));
        self::assertSame([], $this->manager()->recurringTaskIds([]));
        $row = $this->em->getRepository(TaskOccurrence::class)->findOneBy(['task' => $copy]);
        self::assertInstanceOf(TaskOccurrence::class, $row);
        self::assertSame($series, $row->getRecurrence());
        self::assertNotSame('', $row->getId());
        self::assertLessThanOrEqual(new DateTimeImmutable(), $row->getCreatedAt());
        self::assertLessThanOrEqual($series->getUpdatedAt(), $series->getCreatedAt());
    }

    public function testMissedDatesAreRecordedAndOnlyTheLatestIsCreated(): void
    {
        $task   = $this->dated('Water', '2026-11-01');
        $series = $this->manager()->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'day', 1, new DateTimeImmutable()), $this->owner, leadDays: 0);

        self::assertSame('date:2026-11-04', $this->manager()->dueKey($series, new DateTimeImmutable('2026-11-04')));
        $copy = $this->manager()->createOccurrence($series, 'date:2026-11-04');
        self::assertSame('2026-11-04', $copy?->getDueAt()?->format('Y-m-d'));
        $rows = $this->em->getRepository(TaskOccurrence::class)->findBy(['recurrence' => $series], ['occurrenceKey' => 'ASC']);
        self::assertSame(['date:2026-11-01', 'date:2026-11-02', 'date:2026-11-03', 'date:2026-11-04'], array_map(static fn (TaskOccurrence $row): string => $row->getOccurrenceKey(), $rows));
        self::assertTrue($rows[1]->isMissed());
        self::assertNull($rows[1]->getTask());
        self::assertSame(2, $this->em->getRepository(Task::class)->count(['title' => 'Water']), 'one task created, three dates missed');
        self::assertNull($this->manager()->createOccurrence($series, 'date:2026-11-03'), 'a missed date is not created later');
        self::assertNull($this->manager()->createOccurrence($series, 'date:2026-11-09-x'), 'a key that is not a date');
        self::assertNull($this->manager()->createOccurrence($series, 'done:' . $copy?->getId()), 'a completion key on a fixed series');
    }

    public function testAfterCompletionOnceEvenIfReopenedAndSkipMovesOnOnePeriod(): void
    {
        $task   = $this->dated('Water the plants', '2026-11-03');
        $series = $this->manager()->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_AFTER_COMPLETION, 'day', 3, new DateTimeImmutable()), $this->owner, copySubtasks: false);
        self::assertNull($series->getNextDate());
        self::assertSame([], $this->manager()->upcoming($series));
        self::assertNull($this->manager()->completionKey($task), 'not completed yet');

        $task->markCompleted(new DateTimeImmutable('2026-11-03 10:00'));
        $this->em->flush();
        $key = $this->manager()->completionKey($task);
        self::assertSame([$series, 'done:' . $task->getId()], $key);
        $next = $this->manager()->createOccurrence($series, $key[1]);
        self::assertSame('2026-11-06', $next?->getDueAt()?->format('Y-m-d'));

        // FR-007: reopened and completed again: the same key, no second task.
        $task->markIncomplete()->markCompleted(new DateTimeImmutable('2026-11-04'));
        $this->em->flush();
        self::assertNull($this->manager()->completionKey($task), 'no longer the latest occurrence');
        self::assertNull($this->manager()->createOccurrence($series, 'done:' . $task->getId()));

        // FR-020: skip after completion moves on one more period.
        self::assertTrue($this->manager()->skip($series));
        self::assertTrue($series->isSkipNext());
        self::assertTrue($this->manager()->unskip($series));
        $this->manager()->skip($series);
        $next?->markCompleted(new DateTimeImmutable('2026-11-06'));
        $this->em->flush();
        $third = $this->manager()->createOccurrence($series, 'done:' . $next?->getId());
        self::assertSame('2026-11-12', $third?->getDueAt()?->format('Y-m-d'));
        self::assertFalse($series->isSkipNext());
        self::assertNull($this->manager()->createOccurrence($series, 'done:unknown'), 'an unknown task');

        // An after-completion series ends when the next date is past its end.
        $this->manager()->update($series, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_AFTER_COMPLETION, 'day', 3, new DateTimeImmutable(), endCount: 3), false, 1, false, true);
        $third?->markCompleted(new DateTimeImmutable('2026-11-12'));
        $this->em->flush();
        self::assertNull($this->manager()->createOccurrence($series, 'done:' . $third?->getId()));
        self::assertSame(TaskRecurrence::STATUS_ENDED, $series->getStatus());
        self::assertNull($this->manager()->createOccurrence($series, 'date:2026-11-20'), 'a date key on an after-completion series');
    }

    public function testAStartOnlyTaskKeepsItsTimeOfDayAndAnUnknownBoardMovesNothing(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Morning check');
        $task->setStartAt(new DateTimeImmutable('2026-11-02 09:15'));
        $this->em->flush();
        $series = $this->manager()->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'day', 1, new DateTimeImmutable()), $this->owner);

        $copy = $this->manager()->createOccurrence($series, 'date:2026-11-03');
        self::assertSame('2026-11-03 09:15', $copy?->getStartAt()?->format('Y-m-d H:i'));
        self::assertNull($copy?->getDueAt());

        $listener = self::getContainer()->get(TaskRecurrenceBoardListener::class);
        self::assertInstanceOf(TaskRecurrenceBoardListener::class, $listener);
        $listener(new TaskBoardChangedEvent($task, $this->owner, $board->getId(), 'no-such-board', [$task->getId()]));
        self::assertSame($board->getId(), $series->getBoard()->getId());
    }

    public function testNextOnCompletionGivesTheCalendarDateEarly(): void
    {
        $task   = $this->dated('Report', '2026-11-02');
        $series = $this->manager()->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'week', 1, new DateTimeImmutable()), $this->owner, nextOnCompletion: true);
        $task->markCompleted();
        $this->em->flush();

        self::assertSame([$series, 'date:2026-11-09'], $this->manager()->completionKey($task));
        $plain = $this->manager()->create($this->dated('Other', '2026-11-02'), new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'week', 1, new DateTimeImmutable()), $this->owner);
        $other = $plain->getLatestTask();
        self::assertInstanceOf(Task::class, $other);
        $other->markCompleted();
        $this->em->flush();
        self::assertNull($this->manager()->completionKey($other), 'fixed dates without the option');
    }

    public function testSkipUnskipPauseResumeAndStop(): void
    {
        $today  = new DateTimeImmutable('today');
        $task   = $this->dated('Standup', $today->format('Y-m-d'));
        $other  = $this->user('ana@example.com');
        $series = $this->manager()->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'day', 1, $today), $this->owner, leadDays: 0);
        $next   = $today->modify('+1 day')->format('Y-m-d');

        self::assertFalse($this->manager()->skip($series, '1999-01-01'));
        self::assertFalse($this->manager()->skip($series));
        self::assertTrue($this->manager()->skip($series, $next));
        self::assertSame($today->modify('+2 days')->format('Y-m-d'), $series->getNextDate()?->format('Y-m-d'));
        self::assertTrue($this->manager()->unskip($series, $next));
        self::assertSame($next, $series->getNextDate()?->format('Y-m-d'));
        self::assertFalse($this->manager()->unskip($series, $next), 'not skipped');
        $this->manager()->skip($series, $next);
        self::assertFalse($this->manager()->unskip($series, $today->format('Y-m-d')));
        $series->skip($today->format('Y-m-d'), true);
        self::assertFalse($this->manager()->unskip($series, $today->format('Y-m-d')), 'its creation time has come');

        $this->manager()->pause($series);
        self::assertSame(TaskRecurrence::STATUS_PAUSED, $series->getStatus());
        self::assertSame(TaskRecurrence::REASON_MANUAL, $series->getReason());
        self::assertNull($this->manager()->createOccurrence($series, 'date:' . $today->modify('+2 days')->format('Y-m-d')), 'nothing while paused');
        $this->manager()->pause($series);
        $this->manager()->resume($series, $other);
        self::assertTrue($series->isActive());
        self::assertSame($other, $series->getAuthor(), 'who resumes becomes the author');
        self::assertSame('ana@example.com', $series->getAuthorName());
        $this->manager()->resume($series, $this->owner);
        self::assertSame($other, $series->getAuthor(), 'an active series is not resumed');

        $this->manager()->block($series);
        self::assertSame(TaskRecurrence::REASON_PLAN, $series->getReason());
        self::assertTrue($series->isActive());

        $this->manager()->stop($series);
        self::assertSame(TaskRecurrence::STATUS_STOPPED, $series->getStatus());
        $this->manager()->block($series);
        self::assertNull($this->manager()->seriesOf($task), 'a stopped series is gone from the task');
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]), 'its tasks stay');
    }

    public function testAPlanRefusalCreatesNothingAndASuccessClearsTheBlock(): void
    {
        $task   = $this->dated('Invoices', '2026-11-01');
        $series = $this->manager()->create($task, $this->monthly(), $this->owner);
        $this->manager()->block($series);
        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE];

        $this->assertRefused('quota', fn (): ?\Nowo\TaskBoardBundle\Entity\Task => $this->manager()->createOccurrence($series, 'date:2026-12-01'));
        self::assertSame(1, $this->em->getRepository(TaskOccurrence::class)->count([]), 'no occurrence row');
        self::assertSame(1, $this->em->getRepository(Task::class)->count([]));

        $this->quota()->deny = [];
        self::assertInstanceOf(Task::class, $this->manager()->createOccurrence($series, 'date:2026-12-01'));
        self::assertNull($series->getReason());
    }

    public function testAuthorErasureLatestDeletedAndTheEnd(): void
    {
        $task   = $this->dated('Invoices', '2026-11-01');
        $series = $this->manager()->create($task, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'month', 1, new DateTimeImmutable(), endCount: 3), $this->owner);
        $copy   = $this->manager()->createOccurrence($series, 'date:2026-12-01');
        self::assertInstanceOf(Task::class, $copy);

        // FR-006: the latest occurrence deleted: the series goes on from the most recent one left.
        $this->em->remove($copy);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_recurrences SET latest_task_id = NULL WHERE id = ?', [$series->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_occurrences SET task_id = NULL WHERE task_id = ?', [$copy->getId()]);
        $this->clear();
        $series = $this->em->find(TaskRecurrence::class, $series->getId());
        self::assertInstanceOf(TaskRecurrence::class, $series);
        $last = $this->manager()->createOccurrence($series, 'date:2027-01-01');
        self::assertSame('Invoices', $last?->getTitle());
        self::assertSame(TaskRecurrence::STATUS_ENDED, $series->getStatus(), 'three occurrences');

        $other = $this->manager()->create($this->dated('Rent', '2026-11-01'), $this->monthly(), $this->owner);
        self::assertSame(0, $this->manager()->forgetAuthor(new stdClass()));
        self::assertSame(2, $this->manager()->forgetAuthor($this->owner));
        self::assertSame(TaskRecurrence::STATUS_PAUSED, $other->getStatus());
        self::assertSame(TaskRecurrence::REASON_AUTHOR, $other->getReason());
        self::assertNull($other->getAuthor());
        self::assertNull($other->getAuthorName());
        $this->manager()->resume($other, $this->owner);
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_recurrences SET author_id = NULL WHERE id = ?', [$other->getId()]);
        $this->em->refresh($other);
        self::assertNull($this->manager()->createOccurrence($other, 'date:2026-12-01'), 'no author');
        self::assertSame(TaskRecurrence::REASON_AUTHOR, $other->getReason());

        $orphan = $this->manager()->create($this->dated('Gone', '2026-11-01'), $this->monthly(), $this->owner);
        $gone   = $orphan->getLatestTask();
        self::assertInstanceOf(Task::class, $gone);
        $this->em->remove($gone);
        $this->em->flush();
        // What ON DELETE SET NULL does on PostgreSQL and MySQL (the SQLite test schema does not enforce foreign keys).
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_recurrences SET latest_task_id = NULL WHERE id = ?', [$orphan->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE task_board_task_occurrences SET task_id = NULL WHERE recurrence_id = ?', [$orphan->getId()]);
        $this->clear();
        $orphan = $this->em->find(TaskRecurrence::class, $orphan->getId());
        self::assertInstanceOf(TaskRecurrence::class, $orphan);
        self::assertNull($this->manager()->createOccurrence($orphan, 'date:2026-12-01', $this->owner));
        self::assertSame(TaskRecurrence::STATUS_ENDED, $orphan->getStatus(), 'nothing left to copy from');
    }

    public function testRefusalsWhenMakingASeries(): void
    {
        self::$taskBoardConfig = ['recurrence' => ['enabled' => true, 'max_subtasks' => 0]];
        self::ensureKernelShutdown();
        $this->setUp();
        $task = $this->dated('Parent', null);
        $this->task($task->getBoard(), 'Child', 0, $task);
        $this->clear();
        $task = $this->reload($task);

        $refusal = $this->assertRefused('subtree_too_large', fn (): TaskRecurrence => $this->manager()->create($task, $this->monthly(), $this->owner));
        self::assertSame(['%count%' => 1, '%max%' => 0], $refusal->params);
        $series = $this->manager()->create($task, $this->monthly(), $this->owner, copySubtasks: false);
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $series->rule()->anchor->format('Y-m-d'), 'no date: today');
        $this->assertRefused('already_recurring', fn (): TaskRecurrence => $this->manager()->create($task, $this->monthly(), $this->owner, copySubtasks: false));
        $this->assertRefused('subtree_too_large', fn () => $this->manager()->update($series, $this->monthly(), false, 1, true, true));
        $this->manager()->update($series, new TaskRecurrenceRule(TaskRecurrenceRule::MODE_AFTER_COMPLETION, 'day', 2, new DateTimeImmutable()), false, 99, false, false);
        self::assertSame(30, $series->getLeadDays());
        self::assertNull($series->getNextDate());

        $end = $this->manager()->create($this->dated('Once', '2026-11-01'), new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'day', 1, new DateTimeImmutable(), endDate: new DateTimeImmutable('2026-11-01')), $this->owner);
        self::assertSame(TaskRecurrence::STATUS_ENDED, $end->getStatus(), 'no date left');
    }

    public function testASeriesGoesWithItsLatestOccurrenceToAnotherBoard(): void
    {
        $source = $this->board('Source', ['To do', 'Review', 'Done']);
        $target = $this->board('Target', ['Backlog', 'review', 'Done']);
        $task   = $this->task($source, 'Review the contract', 1);
        $task->setDueAt(new DateTimeImmutable('2026-11-01'));
        $series = $this->manager()->create($task, $this->monthly(), $this->owner);
        $plan   = $this->planner()->plan($task, $target);

        $this->mover()->move($task, $target, [], $plan->fingerprint, $this->owner);
        $this->em->refresh($series);
        self::assertSame($target->getId(), $series->getBoard()->getId());
        self::assertSame('review', $series->getStartColumn()?->getName());
        $other = $this->task($source, 'Stays');
        $this->mover()->move($other, $target, [], $this->planner()->plan($other, $target)->fingerprint, $this->owner);
        self::assertInstanceOf(TaskBoard::class, $series->getBoard());
    }

    private function manager(): TaskRecurrenceManager
    {
        $manager = self::getContainer()->get(TaskRecurrenceManager::class);
        self::assertInstanceOf(TaskRecurrenceManager::class, $manager);

        return $manager;
    }

    private function monthly(): TaskRecurrenceRule
    {
        return new TaskRecurrenceRule(TaskRecurrenceRule::MODE_FIXED, 'month', 1, new DateTimeImmutable());
    }

    private function dated(string $title, ?string $due): Task
    {
        $board = $this->em->getRepository(TaskBoard::class)->findOneBy([]) ?? $this->board();
        $task  = $this->task($board, $title);
        $task->setDueAt($due !== null ? new DateTimeImmutable($due) : null);
        $this->em->flush();

        return $task;
    }

    /**
     * @param list<DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private function days(array $dates): array
    {
        return array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates);
    }

    private function row(Task $task, TaskChangeType $type): ?TaskChangeHistory
    {
        foreach ($task->getChangeHistory() as $entry) {
            if ($entry->getChangeType() === $type) {
                return $entry;
            }
        }

        return null;
    }
}
