<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;

use function is_object;
use function is_string;

/**
 * Associates users with tasks (assignee, watcher, etc.).
 */
final readonly class TaskMemberAssigner
{
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
    ) {
    }

    public function assign(Task $task, TaskMemberFormData $data, object $actor): TaskMember
    {
        if (!is_object($data->user)) {
            throw new InvalidArgumentException('User is required.');
        }

        $member = new TaskMember(
            task: $task,
            user: $data->user,
            memberRole: $data->memberRole,
        );

        $task->addMember($member);
        $this->changeRecorder->record(
            $task,
            $actor,
            TaskChangeType::MemberAdded,
            null,
            $this->userLabel($data->user),
            $data->memberRole->value,
        );
        $this->taskRepository->save($task);

        return $member;
    }

    public function unassign(Task $task, string $memberId, object $actor): bool
    {
        foreach ($task->getMembers() as $member) {
            if ($member->getId() !== $memberId) {
                continue;
            }

            $this->changeRecorder->record(
                $task,
                $actor,
                TaskChangeType::MemberRemoved,
                $this->userLabel($member->getUser()),
                null,
                $member->getMemberRole()->value,
            );
            $task->removeMember($member);
            $this->taskRepository->save($task);

            return true;
        }

        return false;
    }

    private function userLabel(object $user): string
    {
        if (property_exists($user, 'email') && is_string($user->email) && $user->email !== '') {
            return $user->email;
        }

        if (property_exists($user, 'username') && is_string($user->username) && $user->username !== '') {
            return $user->username;
        }

        if (method_exists($user, 'getId')) {
            return '#' . $user->getId();
        }

        return 'unknown';
    }
}
