<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Exception\TaskTimeBillableRefusedException;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableService;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;

/**
 * Since 2.2.0 (spec 213): a time store over the bundle's own manual entries, through {@see TaskTimeLogger} as the task page
 * logs them. For a host without its own time store: `api.hooks.time_entries: Nowo\TaskBoardBundle\Api\Hook\ManualTaskApiTimeEntries`.
 * It lists the token's own person's entries on tasks the token may open, and logs on a task only.
 */
final readonly class ManualTaskApiTimeEntries implements TaskApiTimeEntriesInterface, TaskApiTimeEntryBillableInterface
{
    /** The most entries one list answers. */
    public const LIMIT = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskTimeLogger $logger,
        private TaskApiGuard $guard,
        private TaskApiUsersInterface $users,
        // Since 2.12.0: the audited billable setter.
        private TaskTimeBillableService $billable,
        // Since 2.15.0 (database.user_reference).
        private ?TaskBoardUsers $userReferences = null,
    ) {
    }

    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('e', 't')
            ->from(TaskTimeEntry::class, 'e')
            ->join('e.task', 't')
            ->where('e.user = :user')
            ->setParameter('user', $this->userParameter($principal->user))
            ->orderBy('e.loggedAt', 'DESC')
            ->setMaxResults(self::LIMIT);
        if ($query->taskId !== null) {
            $qb->andWhere('t.id = :task')->setParameter('task', $query->taskId);
        }
        if ($query->from instanceof DateTimeImmutable) {
            $qb->andWhere('e.loggedAt >= :from')->setParameter('from', $query->from);
        }
        if ($query->to instanceof DateTimeImmutable) {
            $qb->andWhere('e.loggedAt <= :to')->setParameter('to', $query->to);
        }
        $apiId   = $this->users->apiId($principal->user);
        $entries = [];
        /** @var list<TaskTimeEntry> $rows */
        $rows = $qb->getQuery()->getResult();
        foreach ($rows as $entry) {
            $task = $entry->getTask();
            if ($task instanceof Task && $this->guard->canSeeTask($principal, $task)) {
                $entries[] = $this->entry($entry, $apiId);
            }
        }

        return new TaskApiTimePage($entries);
    }

    public function log(TaskApiPrincipal $principal, ?Task $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
    {
        if (!$task instanceof Task) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid tid: manual time is logged on a task');
        }

        return $this->entry($this->logger->log($task, $principal->user, max(1, intdiv($entry->durationSeconds, 60)), $entry->start, $entry->description, $entry->billable), $this->users->apiId($principal->user));
    }

    /**
     * Since 2.12.0: one of the token's own entries, on a task it reaches and may work on, through {@see TaskTimeBillableService}
     * (the author or a board manager, no locked period, recorded in the task's history).
     */
    public function setBillable(TaskApiPrincipal $principal, string $entryId, bool $billable): TaskApiTimeEntry
    {
        $own  = $this->entityManager->getRepository(TaskTimeEntry::class)->findOneBy(['id' => $entryId, 'user' => $principal->user]);
        $task = $own instanceof TaskTimeEntry ? $own->getTask() : null;
        if (!$task instanceof Task) {
            throw TaskApiRefusal::notFound();
        }
        $task = $this->guard->task($principal, $task->getId(), TaskApiCapability::WorkOnTask);

        try {
            $entry = $this->billable->change($task, $entryId, $principal->user, $billable);
        } catch (TaskTimeBillableRefusedException $e) {
            // The entry is the token's own and on this task: only the edit right or the period lock can refuse it.
            throw $e->errorCode === TaskTimeBillableRefusedException::PERIOD_LOCKED ? new TaskApiRefusal(409, 'TIME_004', 'This time entry can no longer change') : TaskApiRefusal::permissionRefused();
        }

        return $this->entry($entry, $this->users->apiId($principal->user));
    }

    private function entry(TaskTimeEntry $entry, string $apiId): TaskApiTimeEntry
    {
        return new TaskApiTimeEntry($entry->getId(), $apiId, $entry->getLoggedAt(), $entry->getMinutes() * 60, $entry->getTask()?->getId(), $entry->getDescription(), $entry->getBillable());
    }

    /** Since 2.15.0: a user as a DQL parameter (the object for a relation, its id with user_reference: id). */
    private function userParameter(object $user): object|string|null
    {
        return $this->userReferences instanceof TaskBoardUsers ? $this->userReferences->parameter($user) : $user;
    }
}
