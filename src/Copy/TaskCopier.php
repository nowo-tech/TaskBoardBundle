<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Copy;

use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;

use function array_filter;
use function array_map;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function json_encode;
use function max;
use function sort;
use function spl_object_id;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Since 2.3.0 (specs 215, 217): copies a task, with no `Request`: the duplicate of spec 217 and the occurrences of spec 215
 * use it, and a host may call it from a worker.
 *
 * **Copied:** title, description, priority, tags, colour, estimate, documents (the sync's own "Fields" document excepted);
 * by option the dates, the members still assignable on the target and the subtask tree (archived subtasks left out), every
 * copy open, at its depth. **Never copied:** comments, tracked time (manual entries and TimeTrack's aggregate: the copy
 * starts at 0), history (one "Duplicated" row instead), dependencies, links (an import's re-import key included), sync rows,
 * completion, archive state, the number (the ledger gives a new one) and due-alert markers a host keeps per task.
 *
 * Every task is written through {@see TaskManager::create()} and every member through {@see TaskMemberAssigner::assign()},
 * so the history, TASK_CREATED and TASK_MEMBER_ADDED are the UI's. Before the first write it asks the host's quota
 * ({@see TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE}, `$count` = the tasks it will create); the writes are one
 * transaction. A copy is an ordinary task of its creator: the account erasure that reaches the source reaches it.
 */
final readonly class TaskCopier
{
    /** `copy.max_subtasks`: the most subtasks a copy carries. */
    public const DEFAULT_MAX_SUBTASKS = 200;

    public function __construct(
        private TaskManager $tasks,
        private TaskMemberAssigner $members,
        private TaskChangeRecorder $changeRecorder,
        private TaskRepositoryInterface $taskRepository,
        private EntityManagerInterface $entityManager,
        private TaskBoardQuota $quota = new TaskBoardQuota(),
        private int $maxSubtasks = self::DEFAULT_MAX_SUBTASKS,
        private bool $syncEnabled = false,
        // Since audit IC-L1: subtasks the actor cannot view are left out of a copy (none wired: every subtask is copied).
        private ?TaskBoardResourceAccessChecker $access = null,
        // Since audit IC-I3: the copy's TASK_CREATED / TASK_MEMBER_ADDED wait for its commit (none wired: at once).
        private ?TaskEventDeferral $events = null,
        // Since 2.20.0 (Beacon spec 234, T004): `max_subtask_depth`; a copy never creates a task past it.
        private TaskTreeLimits $treeLimits = new TaskTreeLimits(),
    ) {
    }

    /**
     * What {@see self::copy()} would write with these options for `$actor` (subtasks they cannot view are left out and
     * counted in `hidden`). Read-only.
     *
     * @throws TaskCopyRefusedException `subtree_too_large`, `board_archived`, since 2.20.0 `too_deep`
     */
    public function preview(Task $source, TaskCopyOptions $options = new TaskCopyOptions(), ?object $actor = null): TaskCopyPreview
    {
        $target                   = $options->targetBoard ?? $source->getBoard();
        [$tree, $hidden, $height] = $this->visibleTree($source, $options, $actor);
        $this->refuseUnsupported($target, $tree, $options);
        $this->refuseTooDeep($source, $target, $height);

        $copied  = 0;
        $dropped = [];
        foreach ($options->members ? $tree : [] as $task) {
            foreach ($task->getMembers() as $member) {
                if ($this->members->canBeAssigned($target, $member->getUser())) {
                    ++$copied;
                } else {
                    $dropped[] = UserLabel::of($member->getUser());
                }
            }
        }
        $dropped = array_values(array_unique($dropped));
        sort($dropped);

        $ids          = array_map(static fn (Task $task): string => $task->getId(), $tree);
        $timeEntries  = 0;
        $tracked      = 0;
        $dependencies = 0;
        $links        = 0;
        foreach ($tree as $task) {
            $tracked += $task->getTotalTimeSeconds();
            foreach ($task->getTimeEntries() as $entry) {
                ++$timeEntries;
                $tracked += 60 * $entry->getMinutes();
            }
            $dependencies += $task->getOutgoingDependencies()->count() + $task->getIncomingDependencies()->count();
            $links += $task->getLinks()->count();
        }
        $comments = $this->entityManager->getRepository(TaskComment::class)->count(['task' => $ids]);
        $synced   = $this->synced($ids);
        $column   = TaskColumnRule::target($target, $source->getColumn(), false, $options->columnId);

        $facts = [$source->getId(), $target->getId(), $column?->getId(), $ids, $copied, $dropped, $comments, $timeEntries, $tracked, $dependencies, $links, $synced, $options->subtasks, $options->dates, $options->members, $hidden];

        return new TaskCopyPreview(
            targetBoard: $target,
            column: $column,
            tasks: count($tree),
            membersCopied: $copied,
            membersNotCopied: $dropped,
            comments: $comments,
            timeEntries: $timeEntries,
            trackedSeconds: $tracked,
            dependencies: $dependencies,
            links: $links,
            synced: $synced,
            fingerprint: hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR)),
            hidden: $hidden,
        );
    }

    /**
     * Copies `$source` per `$options`, as `$actor`. With `$fingerprint` (a preview's), nothing is written when the facts
     * changed since.
     *
     * @throws TaskCopyRefusedException before anything is written: `subtree_too_large`, `board_archived`, `stale`, `quota`
     */
    public function copy(Task $source, TaskCopyOptions $options, object $actor, ?string $fingerprint = null): TaskCopyResult
    {
        $target = $options->targetBoard ?? $source->getBoard();
        if ($fingerprint !== null && $this->preview($source, $options, $actor)->fingerprint !== $fingerprint) {
            throw new TaskCopyRefusedException('stale');
        }

        return $this->copyChecked($source, $options, $actor, $this->check($source, $options, $actor));
    }

    /**
     * Since 2.3.0 (audit IC-I3): the writes of {@see self::copy()} for a caller that ran {@see self::check()} before its own
     * transaction, so that no refusal (the host's quota) is asked inside it. `$tree` is what `check()` returned.
     *
     * @param list<Task> $tree
     */
    public function copyChecked(Task $source, TaskCopyOptions $options, object $actor, array $tree): TaskCopyResult
    {
        $target = $options->targetBoard ?? $source->getBoard();
        // @igor-ignore - Closure runs synchronously inside wrapInTransaction() and is never stored.
        $write = fn (): TaskCopyResult => $this->entityManager->wrapInTransaction(function () use ($source, $options, $actor, $target, $tree): TaskCopyResult {
            $shift   = $this->shiftDays($source, $options);
            $copies  = [];
            $dropped = [];
            $parent  = $target->getId() === $source->getBoard()->getId() ? $source->getParent() : null;
            foreach ($tree as $task) {
                $isTop                  = $task === $source;
                $copy                   = $this->tasks->create($target, $this->formData($task, $target, $options, $isTop, $shift), $actor, $isTop ? $parent : $copies[(string) $task->getParent()?->getId()], $isTop ? $options->historyContext : null);
                $copies[$task->getId()] = $copy;
                foreach ($task->getDocuments() as $document) {
                    if (!$document->isSyncFields()) {
                        $copy->getDocuments()->add(new TaskDocument($copy, $document->getTitle(), $document->getContent(), $actor, $document->getPosition()));
                    }
                }
                if ($isTop) {
                    $this->changeRecorder->record($copy, $actor, TaskChangeType::Duplicated, $this->label($source), null, 'task:' . $source->getId() . ($options->historyContext !== null ? ' ' . $options->historyContext : ''));
                }
                $this->taskRepository->save($copy);
                foreach ($options->members ? $task->getMembers() : [] as $member) {
                    try {
                        $this->members->assign($copy, new TaskMemberFormData($member->getUser(), $member->getMemberRole()), $actor);
                    } catch (UnassignableUserException) {
                        $label     = UserLabel::of($member->getUser());
                        $dropped[] = $label;
                        $this->changeRecorder->record($copy, $actor, TaskChangeType::MemberNotCopied, null, $label, $member->getMemberRole()->value);
                        $this->taskRepository->save($copy);
                    }
                }
            }

            return new TaskCopyResult($copies[$source->getId()], array_values($copies), array_values(array_unique($dropped)));
        });

        // Audit JY-I6 (since 2.10.1): the copy is committed when its events go out; a failing host listener is logged, never
        // reported as a failed copy (a retry would create a second one).
        return $this->events instanceof TaskEventDeferral ? $this->events->defer($write, false) : $write();
    }

    /**
     * Since 2.3.0: everything {@see self::copy()} refuses before writing, for a caller that writes first (spec 215's
     * occurrence row): the target, the subtree cap and the host's quota. Subtasks `$actor` cannot view are left out.
     *
     * @throws TaskCopyRefusedException `subtree_too_large`, `board_archived`, `quota`, since 2.20.0 `too_deep`
     *
     * @return list<Task> the tasks the copy would create from
     */
    public function check(Task $source, TaskCopyOptions $options, object $actor): array
    {
        $target            = $options->targetBoard ?? $source->getBoard();
        [$tree, , $height] = $this->visibleTree($source, $options, $actor);
        $this->refuseUnsupported($target, $tree, $options);
        $this->refuseTooDeep($source, $target, $height);
        $refusal = $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, $target->getTenantKey(), $target, count($tree), $actor);
        if ($refusal !== null) {
            throw new TaskCopyRefusedException('quota', [], $refusal);
        }

        return $tree;
    }

    /**
     * Audit IC-L1: the tree as `$actor` may see it. A subtask they cannot view is left out with its own subtasks (counted);
     * without an actor or an access checker, every subtask that is not archived.
     *
     * Since 2.20.0 also the height of the copied tree (0: no subtask). Iterative; a task met twice (a loop in the data)
     * refuses the copy (`tree_loop`).
     *
     * @return array{0: list<Task>, 1: int, 2: int}
     */
    private function visibleTree(Task $source, TaskCopyOptions $options, ?object $actor): array
    {
        $order  = [];
        $hidden = 0;
        $height = 0;
        $seen   = [spl_object_id($source) => true];
        $stack  = [[$source, 0]];
        while ($stack !== []) {
            [$task, $depth] = array_pop($stack);
            $order[]        = $task;
            $height         = max($height, $depth);
            // Since 2.20.0 (audit PX-M2): a loop in the data or a tree past the safety cap fails closed.
            if ($depth >= TaskTreeLimits::SAFETY_CAP) {
                throw new TaskCopyRefusedException('too_deep', ['%max%' => TaskTreeLimits::SAFETY_CAP]);
            }
            $kids = $options->subtasks ? array_filter($task->getSubtasks()->toArray(), static fn (Task $child): bool => !$child->isArchived()) : [];
            usort($kids, static fn (Task $a, Task $b): int => $b->getPosition() <=> $a->getPosition());
            foreach ($kids as $child) {
                if ($actor !== null && $this->access instanceof TaskBoardResourceAccessChecker && !$this->access->canViewTask($actor, $child)) {
                    $hidden += count(TaskMovePlanner::subtree($child));

                    continue;
                }
                if (isset($seen[spl_object_id($child)])) {
                    throw new TaskCopyRefusedException('tree_loop');
                }
                $seen[spl_object_id($child)] = true;
                $stack[]                     = [$child, $depth + 1];
            }
        }

        return [$order, $hidden, $height];
    }

    /**
     * @param list<Task> $tree
     *
     * @throws TaskCopyRefusedException
     */
    private function refuseUnsupported(TaskBoard $target, array $tree, TaskCopyOptions $options): void
    {
        if ($target->isArchived()) {
            throw new TaskCopyRefusedException('board_archived');
        }
        $max = $options->maxSubtasks ?? $this->maxSubtasks;
        if (count($tree) - 1 > $max) {
            throw new TaskCopyRefusedException('subtree_too_large', ['%count%' => count($tree) - 1, '%max%' => $max]);
        }
    }

    /**
     * Since 2.20.0: a copy goes next to its source (or to the top level of another board); its deepest task must stay
     * within `max_subtask_depth`, also when the source tree was saved deeper.
     *
     * @throws TaskCopyRefusedException `too_deep`
     */
    private function refuseTooDeep(Task $source, TaskBoard $target, int $height): void
    {
        if ($this->treeLimits->maxSubtaskDepth === null) {
            return;
        }
        $top = $target->getId() === $source->getBoard()->getId() ? TaskTreeLimits::depthOf($source) : 0;
        if (!$this->treeLimits->allowsTree($top, $height)) {
            throw new TaskCopyRefusedException('too_deep', ['%max%' => $this->treeLimits->maxDepth()]);
        }
    }

    private function formData(Task $task, TaskBoard $target, TaskCopyOptions $options, bool $isTop, ?int $shift): TaskFormData
    {
        $column = TaskColumnRule::target($target, $task->getColumn(), false, $isTop ? $options->columnId : null);
        $data   = new TaskFormData(
            title: $isTop && $options->title !== null ? $options->title : $task->getTitle(),
            description: $task->getDescription(),
            priority: $task->getPriority(),
            columnId: $column instanceof BoardColumn ? $column->getId() : null,
            estimatedMinutes: $task->getEstimatedMinutes(),
            tags: $task->getTags(),
            color: $task->getColor(),
        );
        if ($options->reschedule && $isTop) {
            $data->startAt = $options->startAt;
            $data->dueAt   = $options->dueAt;
        } elseif ($options->reschedule || $options->dates) {
            $data->startAt = $this->shifted($task->getStartAt(), $shift);
            $data->dueAt   = $this->shifted($task->getDueAt(), $shift);
        }

        return $data;
    }

    /** With `reschedule`: how many days the copy's dates move (the due date's move, else the start's); otherwise none. */
    private function shiftDays(Task $source, TaskCopyOptions $options): ?int
    {
        if (!$options->reschedule) {
            return null;
        }
        $from = $source->getDueAt() ?? $source->getStartAt();
        $to   = $source->getDueAt() instanceof DateTimeImmutable ? $options->dueAt : $options->startAt;
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
            return 0;
        }

        return (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%r%a');
    }

    private function shifted(?DateTimeImmutable $date, ?int $days): ?DateTimeImmutable
    {
        if (!$date instanceof DateTimeImmutable || $days === null || $days === 0) {
            return $date;
        }

        return $days > 0 ? $date->add(new DateInterval('P' . $days . 'D')) : $date->sub(new DateInterval('P' . -$days . 'D'));
    }

    private function label(Task $task): string
    {
        return $task->getNumber() !== null ? '#' . $task->getNumber() : $task->getTitle();
    }

    /**
     * @param list<string> $ids
     */
    private function synced(array $ids): bool
    {
        return $this->syncEnabled && $this->entityManager->getRepository(TaskSyncItem::class)->count(['task' => $ids]) > 0;
    }
}
