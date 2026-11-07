<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskMemberAddedEvent;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function get_object_vars;
use function is_object;
use function is_string;
use function ucfirst;

/**
 * Associates users with tasks (assignee, watcher, etc.). With an assignee provider (`assignee_provider`, since 1.10)
 * only the users it allows can be added; a new member dispatches {@see TaskBoardEvents::TASK_MEMBER_ADDED}.
 */
final readonly class TaskMemberAssigner
{
    /**
     * @param TaskAssigneeProviderInterface|null $assigneeProvider Who may be a member (none: every user)
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches TASK_MEMBER_ADDED (none: no event)
     */
    public function __construct(
        private TaskRepositoryInterface $taskRepository,
        private TaskChangeRecorder $changeRecorder,
        private ?TaskAssigneeProviderInterface $assigneeProvider = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * The users the member picker offers for the tasks of `$board`; null without an assignee provider (every user).
     *
     * @return list<object>|null
     */
    public function assignableUsers(TaskBoard $board): ?array
    {
        return $this->assigneeProvider?->assignableUsers($board);
    }

    /**
     * @throws UnassignableUserException When the assignee provider refuses the user
     */
    public function assign(Task $task, TaskMemberFormData $data, object $actor): TaskMember
    {
        if (!is_object($data->user)) {
            throw new InvalidArgumentException('User is required.');
        }

        if ($this->assigneeProvider instanceof TaskAssigneeProviderInterface && !$this->assigneeProvider->canBeAssigned($task->getBoard(), $data->user)) {
            throw UnassignableUserException::forBoard($task->getBoard());
        }

        foreach ($task->getMembers() as $existing) {
            if ($existing->getUser() === $data->user && $existing->getMemberRole() === $data->memberRole) {
                return $existing;
            }
        }

        $member = new TaskMember(
            task: $task,
            user: $data->user,
            memberRole: $data->memberRole,
        );

        $task->addMember($member);
        $this->changeRecorder->recordMember($task, $actor, TaskChangeType::MemberAdded, $data->user, $data->memberRole->value, $this->userLabel($data->user));
        $this->taskRepository->save($task);
        $this->eventDispatcher?->dispatch(new TaskMemberAddedEvent($task, $actor, $member), TaskBoardEvents::TASK_MEMBER_ADDED);

        return $member;
    }

    public function unassign(Task $task, string $memberId, object $actor): bool
    {
        foreach ($task->getMembers() as $member) {
            if ($member->getId() !== $memberId) {
                continue;
            }

            $this->changeRecorder->recordMember($task, $actor, TaskChangeType::MemberRemoved, $member->getUser(), $member->getMemberRole()->value, $this->userLabel($member->getUser()));
            $task->removeMember($member);
            $this->taskRepository->save($task);

            return true;
        }

        return false;
    }

    /**
     * The history label of a member: e-mail, else username (getter or public property; since 1.21 a private property
     * no longer crashes the write), else "#id".
     */
    private function userLabel(object $user): string
    {
        $public = get_object_vars($user);
        foreach (['email', 'username'] as $name) {
            $getter = 'get' . ucfirst($name);
            $value  = method_exists($user, $getter) ? $user->{$getter}() : ($public[$name] ?? null);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($user, 'getId')) {
            return '#' . $user->getId();
        }

        return 'unknown';
    }
}
