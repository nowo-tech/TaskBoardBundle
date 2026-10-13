<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Stringable;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_scalar;

/**
 * Per-resource (board / task) authorization for the manage UI.
 *
 * Default rule: a board is visible to its creator, to members of its team (TeamMember rows or the
 * configured team_membership_resolver) and to users holding one of security.manage_roles. A task is
 * additionally visible to its creator and to users attached to it as members (assignee, reviewer…).
 * Listeners on TaskBoardEvents::BOARD_ACCESS_CHECK / TASK_ACCESS_CHECK may override the decision.
 */
final readonly class TaskBoardResourceAccessChecker
{
    /**
     * @param list<string> $manageRoles roles that bypass per-board scoping
     */
    public function __construct(
        private TeamMemberRepositoryInterface $teamMemberRepository,
        private TaskBoardTeamMembershipResolverInterface $teamMembershipResolver,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private array $manageRoles = ['ROLE_ADMIN'],
        private bool $scopingEnabled = true,
    ) {
    }

    public function canViewBoard(object $user, TaskBoard $board): bool
    {
        $event = new BoardAccessCheckEvent($user, $board, $this->defaultBoardDecision($user, $board));
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

        if ($event->isDenied()) {
            return false;
        }

        return $event->isGranted() || $event->isDefaultGranted();
    }

    public function canViewTask(object $user, Task $task): bool
    {
        return $this->resolveTaskAccess($user, $task)->isGranted();
    }

    /**
     * Viewing is required; a listener may additionally mark the task read-only.
     */
    public function canEditTask(object $user, Task $task): bool
    {
        $event = $this->resolveTaskAccess($user, $task);

        return $event->isGranted() && !$event->isReadOnly();
    }

    /**
     * Boards shown on the index: BOARD_LIST_QUERY listeners may replace the candidate list; the result is
     * always filtered through canViewBoard().
     *
     * @param list<TaskBoard> $candidates
     *
     * @return list<TaskBoard>
     */
    public function filterBoards(object $user, array $candidates): array
    {
        $event = new BoardListQueryEvent($user);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_LIST_QUERY);
        $boards = $event->getOverrideList() ?? $candidates;

        return array_values(array_filter($boards, fn (TaskBoard $board): bool => $this->canViewBoard($user, $board)));
    }

    /**
     * Returns an event whose isGranted() reflects the final decision and isReadOnly() the read-only flag.
     */
    private function resolveTaskAccess(object $user, Task $task): TaskAccessCheckEvent
    {
        $event = new TaskAccessCheckEvent($user, $task);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_ACCESS_CHECK);

        if ($event->isDenied()) {
            return $event;
        }

        if (!$event->isGranted() && ($this->isTaskParticipant($user, $task) || $this->canViewBoard($user, $task->getBoard()))) {
            $event->grant();
        }

        return $event;
    }

    private function defaultBoardDecision(object $user, TaskBoard $board): bool
    {
        if (!$this->scopingEnabled || $this->hasManageRole()) {
            return true;
        }

        if (self::sameUser($user, $board->getCreator())) {
            return true;
        }

        $team = $board->getTeam();

        return $team instanceof Team && $this->isTeamMember($user, $team);
    }

    private function isTaskParticipant(object $user, Task $task): bool
    {
        if (self::sameUser($user, $task->getCreator())) {
            return true;
        }

        foreach ($task->getMembers() as $member) {
            if (self::sameUser($user, $member->getUser())) {
                return true;
            }
        }

        return false;
    }

    private function isTeamMember(object $user, Team $team): bool
    {
        $teamId = $team->getId();

        foreach ($this->teamMembershipResolver->resolveTeamIds($user) as $resolvedId) {
            if ((string) $resolvedId === $teamId) {
                return true;
            }
        }

        $userId = self::userId($user);
        if ($userId === null) {
            return false;
        }

        foreach ($this->teamMemberRepository->findByUserId($userId) as $membership) {
            if ($membership->getTeam()->getId() === $teamId) {
                return true;
            }
        }

        return false;
    }

    private function hasManageRole(): bool
    {
        if ($this->authorizationChecker === null) {
            return false;
        }

        foreach ($this->manageRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    private static function sameUser(object $a, object $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $idA = self::userId($a);

        return $idA !== null && $idA === self::userId($b);
    }

    private static function userId(object $user): ?string
    {
        if (!method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();
        if (!is_scalar($id) && !$id instanceof Stringable) {
            return null;
        }

        $id = (string) $id;

        return $id === '' ? null : $id;
    }
}
