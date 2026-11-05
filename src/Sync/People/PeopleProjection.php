<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;

use function array_diff;
use function array_values;
use function count;
use function in_array;

/**
 * Since 1.25: moves what one remote person authored on one board — creator, comment authors, sync-added members
 * (assignees, watchers, checklist subtasks) — to a target user, or back to the person reference (target null). One
 * keyset batch of sync items per call; idempotent (a second call with the same target changes nothing). No
 * notification; one history entry per changed task with `person:` / `user:` tokens (never names).
 *
 * @internal used by the reassigner and the projector
 */
final readonly class PeopleProjection
{
    public const ROLES = [TaskMemberRole::Assignee, TaskMemberRole::Watcher];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPersonRepository $people,
        private TaskChangeRecorder $changeRecorder = new TaskChangeRecorder(),
    ) {
    }

    /**
     * One batch on one board. `$target` null = the person becomes a reference again (the fallback actor writes).
     *
     * @return array{report: ReassignReport, lastItemId: string, done: bool}
     */
    public function apply(TaskSyncPerson $person, ?object $target, object $fallback, TaskBoardConnection $connection, string $afterItemId, int $batchSize): array
    {
        $items = $this->people->itemsAfter($connection, $afterItemId, $batchSize);
        $this->people->prefetchTasks($items);
        $members = [];
        foreach ($this->people->membersOf($person, $items) as $member) {
            $members[$member->getItem()->getId()][] = $member;
        }
        $comments = [];
        foreach ($this->people->commentsOf($person, $items) as $comment) {
            $comments[$comment->getItem()->getId()][] = $comment;
        }
        $writer   = $target ?? $fallback;
        $tokenNew = $target === null ? 'person:' . $person->getId() : 'user:' . SyncUser::id($target);
        $report   = new ReassignReport();
        $last     = $afterItemId;
        foreach ($items as $item) {
            $last = $item->getId();
            /** @var Task $task itemsAfter() joins the task (an item without one is never read) */
            $task     = $item->getTask();
            $changed  = false;
            $previous = null;
            if ($item->getCreatorPerson()?->getId() === $person->getId() && !SyncUser::same($task->getCreator(), $writer)) {
                $previous = $task->getCreator();
                $task->reassignCreator($writer);
                $report  = $report->plus(new ReassignReport(creators: 1));
                $changed = true;
            }
            foreach ($comments[$item->getId()] ?? [] as $syncComment) {
                $comment = $syncComment->getComment();
                if (!SyncUser::same($comment->getAuthor(), $writer)) {
                    $previous ??= $comment->getAuthor();
                    $comment->reassignAuthor($writer);
                    $report  = $report->plus(new ReassignReport(comments: 1));
                    $changed = true;
                }
            }
            [$removed, $added] = $this->members($item, $task, $person, $target, $members[$item->getId()] ?? []);
            if ($removed + $added > 0) {
                $report  = $report->plus(new ReassignReport(membersRemoved: $removed, membersAdded: $added));
                $changed = true;
            }
            if ($changed) {
                $this->changeRecorder->record($task, $writer, TaskChangeType::SyncPersonReassociated, $previous === null ? 'person:' . $person->getId() : 'user:' . SyncUser::id($previous), $tokenNew, $connection->getProvider());
                $report = $report->plus(new ReassignReport(tasks: 1));
            }
        }
        $this->entityManager->flush();

        return ['report' => $report, 'lastItemId' => $last, 'done' => count($items) < $batchSize];
    }

    /**
     * The person's members on one task: sync members of another user are removed, the target gets one per role (unless
     * a member of that role already exists, e.g. added by hand); an unprojected person is listed in the item's
     * `unmatched_people` instead. Returns [removed, added].
     *
     * @param list<TaskSyncMember> $syncMembers
     *
     * @return array{0: int, 1: int}
     */
    private function members(TaskSyncItem $item, Task $task, TaskSyncPerson $person, ?object $target, array $syncMembers): array
    {
        $roles   = [];
        $removed = 0;
        $added   = 0;
        foreach ($syncMembers as $syncMember) {
            $member                              = $syncMember->getMember();
            $roles[$syncMember->getMemberRole()] = true;
            if ($target !== null && SyncUser::same($member->getUser(), $target)) {
                continue;
            }
            $task->removeMember($member);
            $this->entityManager->remove($syncMember);
            $this->entityManager->remove($member);
            ++$removed;
        }
        foreach (self::ROLES as $role) {
            $listed = in_array($person->getId(), $item->unmatchedPeople($role->value), true);
            if ($listed) {
                $roles[$role->value] = true;
            }
            if (!isset($roles[$role->value])) {
                continue;
            }
            if ($target === null) {
                if (!$listed) {
                    $item->setUnmatchedPeople($role->value, [...$item->unmatchedPeople($role->value), $person->getId()]);
                }
                continue;
            }
            if ($listed) {
                $item->setUnmatchedPeople($role->value, array_values(array_diff($item->unmatchedPeople($role->value), [$person->getId()])));
            }
            if ($this->hasMember($task, $target, $role)) {
                continue;
            }
            $member = new TaskMember($task, $target, $role);
            $task->addMember($member);
            $this->entityManager->persist($member);
            $this->entityManager->persist(new TaskSyncMember($item, $member, $person, $role->value));
            ++$added;
        }

        return [$removed, $added];
    }

    private function hasMember(Task $task, object $user, TaskMemberRole $role): bool
    {
        foreach ($task->getMembers() as $member) {
            if ($member->getMemberRole() === $role && SyncUser::same($member->getUser(), $user)) {
                return true;
            }
        }

        return false;
    }
}
