<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncMember;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncPerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

use function array_values;

/**
 * Since 1.25: assignees and watchers of a provider sync through the people directory. Only members the sync added
 * (tracked in `task_board_sync_members`) are ever removed; members added by hand stay. A person who is not projected
 * (unmatched, suggested, external, or mapped to someone without board access) is listed in the item's
 * `unmatched_people` and shown as text. No `TaskMemberAddedEvent` (no notification during a sync).
 *
 * @internal used by the sync writer
 */
final readonly class SyncPeopleWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskSyncPersonRepository $people,
        private TaskChangeRecorder $changeRecorder = new TaskChangeRecorder(),
    ) {
    }

    /**
     * Makes the task's sync members of a role match the remote people; returns whether a member changed.
     *
     * @param list<RemotePersonRef> $refs
     */
    public function syncMembers(PeopleSession $session, TaskSyncItem $item, Task $task, TaskMemberRole $role, array $refs, int $kind, object $actor): bool
    {
        /** @var array<string, TaskSyncPerson> $desired */
        $desired = [];
        foreach ($refs as $ref) {
            $person                    = $session->person($ref, $kind);
            $desired[$person->getId()] = $person;
        }
        $changed = false;
        $kept    = [];
        foreach ($this->existing($item, $role) as $syncMember) {
            $person = $syncMember->getPerson();
            $member = $syncMember->getMember();
            $user   = isset($desired[$person->getId()]) ? $session->projected($person) : null;
            if ($user !== null && SyncUser::same($member->getUser(), $user)) {
                $kept[$person->getId()] = true;
                continue;
            }
            $task->removeMember($member);
            $this->entityManager->remove($syncMember);
            $this->entityManager->remove($member);
            $this->changeRecorder->recordMember($task, $actor, TaskChangeType::MemberRemoved, $member->getUser(), $role->value);
            $changed = true;
        }
        $unmatched = [];
        foreach ($desired as $personId => $person) {
            $user = $session->projected($person);
            if ($user === null) {
                $unmatched[] = $personId;
                continue;
            }
            if (isset($kept[$personId]) || $this->hasMember($task, $user, $role)) {
                continue;
            }
            $member = new TaskMember($task, $user, $role);
            $task->addMember($member);
            $this->entityManager->persist($member);
            $this->entityManager->persist(new TaskSyncMember($item, $member, $person, $role->value));
            $this->changeRecorder->recordMember($task, $actor, TaskChangeType::MemberAdded, $user, $role->value);
            $changed = true;
        }
        if ($item->unmatchedPeople($role->value) !== $unmatched) {
            $item->setUnmatchedPeople($role->value, $unmatched);
        }

        return $changed;
    }

    /**
     * The sync members of a role on an item (pending ones of this unit of work included).
     *
     * @return list<TaskSyncMember>
     */
    private function existing(TaskSyncItem $item, TaskMemberRole $role): array
    {
        $found = [];
        foreach ($this->people->membersOfItem($item) as $syncMember) {
            if ($syncMember->getMemberRole() === $role->value) {
                $found[$syncMember->getId()] = $syncMember;
            }
        }
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof TaskSyncMember && $entity->getItem() === $item && $entity->getMemberRole() === $role->value) {
                $found[$entity->getId()] = $entity;
            }
        }

        return array_values($found);
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
