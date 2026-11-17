<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Menu;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_values;

/**
 * Since 2.22.0 (context menus): which entries of the task and column menus the signed-in user may use, by the bundle's own
 * access decisions — the ones the routes behind the entries check again on the server:
 *
 * - Open, Copy link: whoever sees the task (the page only lists tasks the user may view);
 * - Edit, Assign (to me / remove me / other people), Status, Priority, Due date, Tags, Archive:
 *   {@see TaskBoardResourceAccessChecker::canEditTask()};
 * - Start timer: the TimeTrack integration ({@see TaskTimerStarterInterface}) and
 *   {@see TaskBoardResourceAccessChecker::canTrackTime()};
 * - Duplicate, Move to…: `move.enabled`, canEditTask() and {@see TaskBoardResourceAccessChecker::canEditBoard()};
 * - Delete: {@see TaskBoardResourceAccessChecker::canManageBoard()};
 * - column headers: Rename with {@see TaskBoardResourceAccessChecker::canManageColumns()}, Add task with canEditBoard(),
 *   Hide / collapse for everyone (a view setting of the browser).
 *
 * Stateless: the per-page decisions live in the {@see TaskMenuContext} it returns.
 */
final readonly class TaskMenuBuilder
{
    public function __construct(
        private TaskBoardResourceAccessChecker $access,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $moveEnabled = false,
        private ?TaskTimerStarterInterface $timer = null,
    ) {
    }

    /** The decisions of one board page for the signed-in user; null without one (no menu at all). */
    public function context(TaskBoard $board): ?TaskMenuContext
    {
        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }
        $canEditBoard = $this->access->canEditBoard($user, $board);

        return new TaskMenuContext(
            user: $user,
            board: $board,
            canEditBoard: $canEditBoard,
            canManageBoard: $canEditBoard && $this->access->canManageBoard($user, $board),
            canManageColumns: $canEditBoard && $this->access->canManageColumns($user, $board),
            canEditTask: $this->access->taskEditDecider($user, $board),
            copyEnabled: $this->moveEnabled,
            timerEnabled: $this->timer?->isAvailable() === true,
        );
    }

    public function forTask(Task $task, TaskMenuContext $context): TaskMenu
    {
        $edit     = $context->canEditTask($task);
        $writable = $edit && !$task->isArchived();
        $member   = $writable && $this->isMember($task, $context->user);

        return new TaskMenu(
            task: $task,
            edit: $edit,
            assignMe: $writable && !$member,
            unassignMe: $member,
            assignOthers: $writable,
            statuses: $writable ? array_values(array_filter($task->getBoard()->getColumns()->toArray(), static fn (BoardColumn $column): bool => $column->getId() !== $task->getColumn()?->getId())) : [],
            priorities: $writable ? array_values(array_filter(TaskPriority::cases(), static fn (TaskPriority $priority): bool => $priority !== $task->getPriority())) : [],
            dueChoices: $writable ? TaskMenuOperation::DUE_CHOICES : [],
            tags: $writable,
            timer: $context->timerEnabled && !$task->isArchived() && $this->access->canTrackTime($context->user, $task),
            copy: $context->copyEnabled && $edit && $context->canEditBoard,
            archive: $writable,
            delete: $context->canManageBoard,
        );
    }

    /** Whether the user is one of the task's members (any role): "Remove me" instead of "Assign to me". */
    public function isMember(Task $task, object $user): bool
    {
        return $this->memberIdsOf($task, $user) !== [];
    }

    /**
     * The task's member rows of the user (each role is a row), by object or by id (`database.user_reference: id`).
     *
     * @return list<string>
     */
    public function memberIdsOf(Task $task, object $user): array
    {
        $userId = ActorId::of($user);
        $ids    = [];
        foreach ($task->getMembers() as $member) {
            if ($member->getUser() === $user || ($userId !== null && ActorId::of($member->getUser()) === $userId)) {
                $ids[] = $member->getId();
            }
        }

        return $ids;
    }
}
