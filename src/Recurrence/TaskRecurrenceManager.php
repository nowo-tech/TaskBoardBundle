<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Recurrence;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Copy\TaskColumnRule;
use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskOccurrence;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Since 2.3.0 (spec 215, `recurrence.enabled`): series of repeating tasks. No scheduler: the host decides when, and asks
 * {@see self::dueSeries()} and {@see self::dueKey()} (fixed dates) or {@see self::completionKey()} (after a completion),
 * then calls {@see self::createOccurrence()}, which is idempotent: the occurrence row (unique per series and key) and the
 * new task are written in one transaction, so a re-run or a redelivered message creates nothing more.
 *
 * Occurrences are copies of the latest one through {@see TaskCopier} (history, events and the quota of any new task), as
 * the series' author. The host checks its plan limits and the author's access before calling; the copier asks
 * `QUOTA_CHECK` `task_create` too. A task that follows a provider cannot be made recurring.
 */
final readonly class TaskRecurrenceManager
{
    public const DEFAULT_MAX_SUBTASKS = 200;

    public const MAX_LEAD_DAYS = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskCopier $copier,
        private TaskRecurrencePlanner $planner = new TaskRecurrencePlanner(),
        private ClockInterface $clock = new NativeClock(),
        private int $maxSubtasks = self::DEFAULT_MAX_SUBTASKS,
        private bool $syncEnabled = false,
        // Since audit IC-I3.
        private ?TaskEventDeferral $events = null,
    ) {
    }

    /** The series whose latest occurrence is `$task` and that has not ended or been stopped; null otherwise. */
    public function seriesOf(Task $task): ?TaskRecurrence
    {
        $series = $this->entityManager->getRepository(TaskRecurrence::class)->findBy(['latestTask' => $task, 'status' => [TaskRecurrence::STATUS_ACTIVE, TaskRecurrence::STATUS_PAUSED]], ['createdAt' => 'DESC'], 1);

        return $series[0] ?? null;
    }

    /**
     * Since 2.3.0: the ids of the tasks of these boards that are the latest occurrence of a running series, in one query
     * (the repeat icon of the board and list views).
     *
     * @param list<string> $boardIds
     *
     * @return array<string, true>
     */
    public function recurringTaskIds(array $boardIds): array
    {
        if ($boardIds === []) {
            return [];
        }
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(r.latestTask) AS id')->from(TaskRecurrence::class, 'r')
            ->where('r.board IN (:boards)')->andWhere('r.status IN (:statuses)')->andWhere('r.latestTask IS NOT NULL')
            ->setParameter('boards', $boardIds)->setParameter('statuses', [TaskRecurrence::STATUS_ACTIVE, TaskRecurrence::STATUS_PAUSED])
            ->getQuery()->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row['id']] = true;
        }

        return $ids;
    }

    /**
     * Makes `$task` repeat. Its own date (due, else start, else today) is the first occurrence.
     *
     * @throws TaskCopyRefusedException `synced_task`, `already_recurring`, `subtree_too_large`
     */
    public function create(Task $task, TaskRecurrenceRule $rule, object $author, bool $nextOnCompletion = false, int $leadDays = 1, bool $copySubtasks = true, bool $copyMembers = true): TaskRecurrence
    {
        $this->refuse($task, $copySubtasks);
        if ($this->seriesOf($task) instanceof TaskRecurrence) {
            throw new TaskCopyRefusedException('already_recurring');
        }
        $first  = $this->dayOf($task) ?? $this->today();
        $rule   = new TaskRecurrenceRule($rule->mode, $rule->unit, $rule->interval, $first, $rule->weekdays, $rule->monthDay, $rule->endDate, $rule->endCount);
        $column = $task->getColumn() instanceof BoardColumn && in_array($task->getColumn(), TaskColumnRule::openColumns($task->getBoard()), true) ? $task->getColumn() : null;

        $series = new TaskRecurrence($task->getBoard(), $task, $author, $rule->mode, $rule->unit, $rule->interval, $first, $rule->weekdays, $rule->monthDay, $rule->endDate, $rule->endCount, $nextOnCompletion, $this->lead($leadDays), $copySubtasks, $copyMembers, $column);
        $this->entityManager->persist($series);
        $this->entityManager->persist(new TaskOccurrence($series, $this->firstKey($series, $task), $task));
        $this->schedule($series, $first);
        $this->entityManager->flush();

        return $series;
    }

    /**
     * Changes the rule; the dates follow from the current occurrence's date.
     *
     * @throws TaskCopyRefusedException `subtree_too_large`
     */
    public function update(TaskRecurrence $series, TaskRecurrenceRule $rule, bool $nextOnCompletion, int $leadDays, bool $copySubtasks, bool $copyMembers): void
    {
        $latest = $series->getLatestTask();
        if ($latest instanceof Task) {
            $this->refuse($latest, $copySubtasks);
        }
        $anchor = $latest instanceof Task ? ($this->dayOf($latest) ?? $series->rule()->anchor) : $series->rule()->anchor;
        $series->change(new TaskRecurrenceRule($rule->mode, $rule->unit, $rule->interval, $anchor, $rule->weekdays, $rule->monthDay, $rule->endDate, $rule->endCount), $nextOnCompletion, $this->lead($leadDays), $copySubtasks, $copyMembers);
        $this->schedule($series, $anchor);
        $this->entityManager->flush();
    }

    /** Nothing is created while paused. */
    public function pause(TaskRecurrence $series, string $reason = TaskRecurrence::REASON_MANUAL): void
    {
        if ($series->isActive()) {
            $series->setStatus(TaskRecurrence::STATUS_PAUSED, $reason);
            $this->entityManager->flush();
        }
    }

    /**
     * Resumes a paused series as `$actor`, who becomes its author. The next date is the first one from today: dates that
     * passed while paused are neither created nor counted as missed.
     */
    public function resume(TaskRecurrence $series, object $actor): void
    {
        if ($series->getStatus() !== TaskRecurrence::STATUS_PAUSED) {
            return;
        }
        $series->setAuthor($actor)->setStatus(TaskRecurrence::STATUS_ACTIVE);
        $this->schedule($series, $this->today()->modify('-1 day'));
        $this->entityManager->flush();
    }

    /** Stops the series for good; its tasks stay. */
    public function stop(TaskRecurrence $series): void
    {
        $series->setStatus(TaskRecurrence::STATUS_STOPPED);
        $this->entityManager->flush();
    }

    /**
     * Skips an upcoming date (Y-m-d) of a fixed series, or, after completion, the next period. False when the date is not
     * one of the upcoming ones.
     */
    public function skip(TaskRecurrence $series, ?string $date = null): bool
    {
        if ($series->rule()->mode === TaskRecurrenceRule::MODE_AFTER_COMPLETION) {
            $series->setSkipNext(true);
            $this->entityManager->flush();

            return true;
        }
        $upcoming = array_map(static fn (DateTimeImmutable $day): string => $day->format('Y-m-d'), $this->upcoming($series, 10));
        if ($date === null || !in_array($date, $upcoming, true)) {
            return false;
        }
        $series->skip($date, true);
        $this->schedule($series, $this->lastCreatedDay($series));
        $this->entityManager->flush();

        return true;
    }

    /** Undoes a skip while that date's creation time has not come. False otherwise. */
    public function unskip(TaskRecurrence $series, ?string $date = null): bool
    {
        if ($series->rule()->mode === TaskRecurrenceRule::MODE_AFTER_COMPLETION) {
            $series->setSkipNext(false);
            $this->entityManager->flush();

            return true;
        }
        if ($date === null || !in_array($date, $series->getSkippedDates(), true) || $this->createAt($series, new DateTimeImmutable($date)) <= $this->today()) {
            return false;
        }
        $series->skip($date, false);
        $this->schedule($series, $this->lastCreatedDay($series));
        $this->entityManager->flush();

        return true;
    }

    /**
     * The next dates of a fixed series (skipped ones left out); none after completion.
     *
     * @return list<DateTimeImmutable>
     */
    public function upcoming(TaskRecurrence $series, int $count = 3): array
    {
        $next = $series->getNextDate();

        return $next instanceof DateTimeImmutable ? $this->planner->nextDates($series->rule(), $next->modify('-1 day'), $count, $series->getSkippedDates(), $series->getOccurrences()) : [];
    }

    /**
     * The active series of fixed dates whose next occurrence is due at `$now` (its date minus the lead days), on boards that
     * are not archived. One query.
     *
     * @return list<TaskRecurrence>
     */
    public function dueSeries(?DateTimeImmutable $now = null, int $limit = 500): array
    {
        $today = ($now ?? $this->clock->now())->setTime(0, 0);
        /** @var list<TaskRecurrence> $candidates */
        $candidates = $this->entityManager->createQueryBuilder()
            ->select('r', 'b')->from(TaskRecurrence::class, 'r')->join('r.board', 'b')
            ->where('r.status = :active')->andWhere('r.nextDate IS NOT NULL')->andWhere('r.nextDate <= :horizon')->andWhere('b.archivedAt IS NULL')
            ->setParameter('active', TaskRecurrence::STATUS_ACTIVE)
            ->setParameter('horizon', $today->modify('+' . self::MAX_LEAD_DAYS . ' days'), 'date_immutable')
            ->orderBy('r.nextDate')->setMaxResults($limit)
            ->getQuery()->getResult();

        return array_values(array_filter($candidates, fn (TaskRecurrence $series): bool => $this->dueKey($series, $today) !== null));
    }

    /**
     * The key of the occurrence a fixed series owes at `$now`: the latest planned date whose creation time has come (older
     * ones are recorded as missed when it is created). Null when nothing is due.
     */
    public function dueKey(TaskRecurrence $series, ?DateTimeImmutable $now = null): ?string
    {
        $today = ($now ?? $this->clock->now())->setTime(0, 0);
        $due   = null;
        foreach ($this->upcoming($series, 400) as $date) {
            if ($this->createAt($series, $date) > $today) {
                break;
            }
            $due = $date;
        }

        return $due instanceof DateTimeImmutable ? 'date:' . $due->format('Y-m-d') : null;
    }

    /**
     * After `$task` was completed: the series and the key of the occurrence it owes, when the task is the latest occurrence
     * of an active series after completion, or of fixed dates with "next on completion". Completing it again gives the same
     * key, so a reopened and re-completed task creates nothing more.
     *
     * @return array{0: TaskRecurrence, 1: string}|null
     */
    public function completionKey(Task $task): ?array
    {
        $series = $this->seriesOf($task);
        if (!$series instanceof TaskRecurrence || !$series->isActive() || !$task->isCompleted()) {
            return null;
        }
        if ($series->getMode() === TaskRecurrenceRule::MODE_AFTER_COMPLETION) {
            return [$series, 'done:' . $task->getId()];
        }
        $next = $this->upcoming($series, 1)[0] ?? null;

        return $series->isNextOnCompletion() && $next instanceof DateTimeImmutable ? [$series, 'date:' . $next->format('Y-m-d')] : null;
    }

    /**
     * Creates the occurrence `$key` as `$actor` (default: the series' author). Null, writing nothing, when it exists already,
     * the series is not active, the key is not owed, or nothing is left to copy from (the series then ends). A refusal of
     * the copier (`quota`, `subtree_too_large`, `board_archived`) is thrown before anything is written.
     *
     * A unique-key race lost to a concurrent run returns null; Doctrine closes the entity manager on that failure, so a
     * worker resets it, as after any failed flush.
     *
     * @throws TaskCopyRefusedException
     */
    public function createOccurrence(TaskRecurrence $series, string $key, ?object $actor = null): ?Task
    {
        $actor ??= $series->getAuthor();
        if (!$series->isActive() || $this->entityManager->getRepository(TaskOccurrence::class)->count(['recurrence' => $series, 'occurrenceKey' => $key]) > 0) {
            return null;
        }
        if ($actor === null) {
            $this->pause($series, TaskRecurrence::REASON_AUTHOR);

            return null;
        }
        $source = $this->source($series);
        if (!$source instanceof Task) {
            $series->setStatus(TaskRecurrence::STATUS_ENDED);
            $this->entityManager->flush();

            return null;
        }
        [$date, $missed] = $this->dateOf($series, $key);
        if (!$date instanceof DateTimeImmutable) {
            return null;
        }
        $options = $this->options($series, $source, $date);
        // Audit IC-I3: every refusal before the transaction, which therefore never closes the entity manager on one, so a
        // host's block() still works; the events of the new tasks wait for the commit.
        $tree = $this->copier->check($source, $options, $actor);

        try {
            // @igor-ignore - Closure runs synchronously inside wrapInTransaction() and is never stored.
            $write = fn (): Task => $this->entityManager->wrapInTransaction(function () use ($series, $key, $source, $options, $actor, $date, $missed, $tree): Task {
                $occurrence = new TaskOccurrence($series, $key);
                $this->entityManager->persist($occurrence);
                foreach ($missed as $day) {
                    $this->entityManager->persist(new TaskOccurrence($series, 'date:' . $day->format('Y-m-d'), null, true));
                }
                $this->entityManager->flush();

                $copy = $this->copier->copyChecked($source, $options, $actor, $tree)->task;
                $occurrence->setTask($copy);
                $series->advance($copy);
                if ($series->getReason() === TaskRecurrence::REASON_PLAN) {
                    $series->setStatus(TaskRecurrence::STATUS_ACTIVE);
                }
                $this->schedule($series, $series->getMode() === TaskRecurrenceRule::MODE_FIXED ? $date : null);
                $this->entityManager->flush();

                return $copy;
            });

            // Audit JY-I6 (since 2.10.1): the copy is committed when its events go out; a failing host listener is logged, never
            // reported as a failed copy (a retry would create a second one).
            return $this->events instanceof TaskEventDeferral ? $this->events->defer($write, false) : $write();
            // @codeCoverageIgnoreStart
        } catch (UniqueConstraintViolationException) {
            // A concurrent run wrote the same key between the check above and this insert: it created the occurrence.
            return null;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * A host's plan refused the occurrence: the series stays active, says so, and is tried again on the next run.
     */
    public function block(TaskRecurrence $series): void
    {
        if ($series->isActive()) {
            $series->setStatus(TaskRecurrence::STATUS_ACTIVE, TaskRecurrence::REASON_PLAN);
            $this->entityManager->flush();
        }
    }

    /**
     * The account of `$user` is being erased: their series pause (`author_no_access`) and forget them, name included.
     * Returns how many series changed.
     */
    public function forgetAuthor(object $user): int
    {
        $id = SyncUser::id($user);
        if ($id === null) {
            return 0;
        }
        $changed = 0;
        foreach ($this->entityManager->getRepository(TaskRecurrence::class)->findBy(['author' => $id]) as $series) {
            if ($series->isActive()) {
                $series->setStatus(TaskRecurrence::STATUS_PAUSED, TaskRecurrence::REASON_AUTHOR);
            }
            $series->setAuthor(null, true);
            ++$changed;
        }
        $this->entityManager->flush();

        return $changed;
    }

    /** Whether `$task` follows a provider (it cannot repeat, FR-008). */
    public function isSynced(Task $task): bool
    {
        return $this->syncEnabled && $this->entityManager->getRepository(TaskSyncItem::class)->count(['task' => $task]) > 0;
    }

    /**
     * @throws TaskCopyRefusedException
     */
    private function refuse(Task $task, bool $subtasks): void
    {
        if ($this->isSynced($task)) {
            throw new TaskCopyRefusedException('synced_task');
        }
        $count = count(TaskMovePlanner::subtree($task)) - 1;
        if ($subtasks && $count > $this->maxSubtasks) {
            throw new TaskCopyRefusedException('subtree_too_large', ['%count%' => $count, '%max%' => $this->maxSubtasks]);
        }
    }

    /** The task to copy: the latest occurrence, else the most recent one still there. */
    private function source(TaskRecurrence $series): ?Task
    {
        if ($series->getLatestTask() instanceof Task) {
            return $series->getLatestTask();
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o')->from(TaskOccurrence::class, 'o')
            ->where('o.recurrence = :series')->andWhere('o.task IS NOT NULL')
            ->setParameter('series', $series)->orderBy('o.createdAt', 'DESC')->setMaxResults(1)
            ->getQuery()->getResult();
        $row = $rows[0] ?? null;

        return $row instanceof TaskOccurrence ? $row->getTask() : null;
    }

    /**
     * The date an occurrence key stands for, and the planned dates it passes over (missed); [null, []] when not owed.
     *
     * @return array{0: DateTimeImmutable|null, 1: list<DateTimeImmutable>}
     */
    private function dateOf(TaskRecurrence $series, string $key): array
    {
        if (str_starts_with($key, 'done:')) {
            $completed = $this->entityManager->find(Task::class, substr($key, 5));
            // Audit IC-I6: only an occurrence of this series counts.
            $ours = $completed instanceof Task && ($series->getLatestTask() === $completed || $this->entityManager->getRepository(TaskOccurrence::class)->count(['recurrence' => $series, 'task' => $completed]) > 0);
            $at   = $ours ? $completed->getCompletedAt() : null;
            if ($series->getMode() !== TaskRecurrenceRule::MODE_AFTER_COMPLETION || !$at instanceof DateTimeImmutable) {
                return [null, []];
            }
            $date = $this->planner->afterCompletion($series->rule(), $at, $series->isSkipNext() ? 2 : 1);
            if (!$date instanceof DateTimeImmutable || ($series->rule()->endCount !== null && $series->getOccurrences() >= $series->rule()->endCount)) {
                $series->setStatus(TaskRecurrence::STATUS_ENDED);
                $this->entityManager->flush();

                return [null, []];
            }

            return [$date, []];
        }
        if (preg_match('/^date:(\d{4}-\d{2}-\d{2})$/D', $key, $match) !== 1) {
            return [null, []];
        }
        $missed = [];
        foreach ($this->upcoming($series, 400) as $date) {
            if ($date->format('Y-m-d') === $match[1]) {
                return [$date, $missed];
            }
            $missed[] = $date;
        }

        return [null, []];
    }

    private function options(TaskRecurrence $series, Task $source, DateTimeImmutable $date): TaskCopyOptions
    {
        $start    = $source->getStartAt();
        $due      = $source->getDueAt();
        $newStart = null;
        $newDue   = $date;
        if ($due instanceof DateTimeImmutable) {
            $newDue = $date->setTime((int) $due->format('G'), (int) $due->format('i'));
            if ($start instanceof DateTimeImmutable) {
                $days     = (int) $start->setTime(0, 0)->diff($due->setTime(0, 0))->format('%r%a');
                $newStart = $date->modify(sprintf('%+d days', -$days))->setTime((int) $start->format('G'), (int) $start->format('i'));
            }
        } elseif ($start instanceof DateTimeImmutable) {
            $newDue   = null;
            $newStart = $date->setTime((int) $start->format('G'), (int) $start->format('i'));
        }
        $column = $series->getStartColumn();

        return new TaskCopyOptions(
            targetBoard: $series->getBoard(),
            columnId: $column?->getId(),
            subtasks: $series->copiesSubtasks(),
            members: $series->copiesMembers(),
            reschedule: true,
            startAt: $newStart,
            dueAt: $newDue,
            historyContext: 'recurring',
            maxSubtasks: $this->maxSubtasks,
        );
    }

    /**
     * Sets the next date of a fixed series after `$after` (null after completion); none left: the series ends.
     */
    private function schedule(TaskRecurrence $series, ?DateTimeImmutable $after): void
    {
        if ($series->getMode() !== TaskRecurrenceRule::MODE_FIXED) {
            $series->setNextDate(null);

            return;
        }
        $next = $this->planner->nextDates($series->rule(), $after, 1, $series->getSkippedDates(), $series->getOccurrences())[0] ?? null;
        $series->setNextDate($next);
        if (!$next instanceof DateTimeImmutable && $series->isActive()) {
            $series->setStatus(TaskRecurrence::STATUS_ENDED);
        }
    }

    /** The last date this series created or missed (its occurrence rows), else the day before its anchor. */
    private function lastCreatedDay(TaskRecurrence $series): DateTimeImmutable
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('o.occurrenceKey AS k')->from(TaskOccurrence::class, 'o')
            ->where('o.recurrence = :series')->andWhere('o.occurrenceKey LIKE :prefix')
            ->setParameter('series', $series)->setParameter('prefix', 'date:%')
            ->orderBy('o.occurrenceKey', 'DESC')->setMaxResults(1)
            ->getQuery()->getArrayResult();

        return isset($rows[0]['k']) ? new DateTimeImmutable(substr((string) $rows[0]['k'], 5)) : $series->rule()->anchor->modify('-1 day');
    }

    private function createAt(TaskRecurrence $series, DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify('-' . $series->getLeadDays() . ' days');
    }

    private function firstKey(TaskRecurrence $series, Task $task): string
    {
        return $series->getMode() === TaskRecurrenceRule::MODE_FIXED ? 'date:' . $series->rule()->anchor->format('Y-m-d') : 'first:' . $task->getId();
    }

    private function dayOf(Task $task): ?DateTimeImmutable
    {
        return ($task->getDueAt() ?? $task->getStartAt())?->setTime(0, 0);
    }

    private function lead(int $days): int
    {
        return max(0, min(self::MAX_LEAD_DAYS, $days));
    }

    private function today(): DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
