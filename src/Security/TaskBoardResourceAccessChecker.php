<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Closure;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\ColumnSetAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function array_map;
use function in_array;
use function is_scalar;

/**
 * Per-resource (board / task) authorization for the manage UI.
 *
 * Default rule: a board is visible to its creator, to members of its team (TeamMember rows or the
 * configured team_membership_resolver) and to users holding one of security.manage_roles. A task is
 * additionally visible to its creator and to users attached to it as members (assignee, reviewer…).
 * Listeners on TaskBoardEvents::BOARD_ACCESS_CHECK / TASK_ACCESS_CHECK may override the decision.
 *
 * Writes (since 1.10): changing a board (columns, new tasks, import) needs canEditBoard() (since 2.5.0 the columns
 * also canManageColumns()): view access that no
 * BOARD_ACCESS_CHECK listener marked read-only. Tasks of a read-only board are read-only too, unless a
 * TASK_ACCESS_CHECK listener grants the task explicitly (and does not mark it read-only).
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
        // Since 2.23.0 (Beacon spec 242): `comments.readers_can_comment`, the default of who may ask a question.
        private bool $readersCanComment = true,
        // Since 2.29.0: `security.manage_roles` are the roles of the user asked about, not of the signed-in user.
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * @param bool $manageRoles since 2.13.0: false leaves `security.manage_roles` out of the default decision (up to 2.28
     *                          they were the roles of the signed-in user; since 2.29.0 they are `$user`'s own)
     */
    public function canViewBoard(object $user, TaskBoard $board, bool $manageRoles = true): bool
    {
        return $this->isBoardViewable($this->resolveBoardAccess($user, $board, $manageRoles));
    }

    /**
     * Whether the user may change the board (add / edit / reorder columns, create tasks, import): view access that
     * no BOARD_ACCESS_CHECK listener marked read-only (since 1.10).
     */
    public function canEditBoard(object $user, TaskBoard $board): bool
    {
        $event = $this->resolveBoardAccess($user, $board);

        return $this->isBoardViewable($event) && !$event->isReadOnly();
    }

    /**
     * Since 1.23: whether the user may manage the board's provider connection (link a remote list, refresh, unlink).
     * Needs {@see self::canEditBoard()} and the {@see BoardAccessCheckEvent::ACTION_MANAGE_CONNECTION} decision of
     * BOARD_ACCESS_CHECK, whose default is the board's creator or a `security.manage_roles` holder (listeners may
     * grant or deny it).
     */
    public function canManageBoardConnection(object $user, TaskBoard $board): bool
    {
        if (!$this->canEditBoard($user, $board)) {
            return false;
        }

        $default = !$this->scopingEnabled || $this->hasManageRole($user) || $this->sameUser($user, $board->getCreator());
        $event   = new BoardAccessCheckEvent($user, $board, $default, BoardAccessCheckEvent::ACTION_MANAGE_CONNECTION);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

        // Since 1.25: a connection imports tasks (and people and time), so the import decision applies too.
        return $this->isBoardViewable($event) && $this->importDecision($user, $board);
    }

    /**
     * Since 1.25: whether the user may bring tasks into the board from outside (file import into the board or under
     * one of its tasks). Needs {@see self::canEditBoard()} and the {@see BoardAccessCheckEvent::ACTION_IMPORT_TASKS}
     * decision of BOARD_ACCESS_CHECK, granted by default (listeners narrow it, e.g. to a dedicated permission).
     * {@see self::canManageBoardConnection()} asks the same decision.
     */
    public function canImportTasks(object $user, TaskBoard $board): bool
    {
        return $this->canEditBoard($user, $board) && $this->importDecision($user, $board);
    }

    /**
     * Since 1.25: whether the user may import a file of subtasks under the task: edit rights on the task (a task
     * participant qualifies, as since 1.18) and the {@see BoardAccessCheckEvent::ACTION_IMPORT_TASKS} decision.
     */
    public function canImportIntoTask(object $user, Task $task): bool
    {
        return $this->canEditTask($user, $task) && $this->importDecision($user, $task->getBoard());
    }

    private function importDecision(object $user, TaskBoard $board): bool
    {
        $event = new BoardAccessCheckEvent($user, $board, true, BoardAccessCheckEvent::ACTION_IMPORT_TASKS);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

        return $this->isBoardViewable($event);
    }

    /**
     * Since 1.25: whether the user may empty or delete the board ({@see TaskBoardEraser}).
     * Needs {@see self::canEditBoard()} and the {@see BoardAccessCheckEvent::ACTION_MANAGE} decision of
     * BOARD_ACCESS_CHECK, whose default is the board's creator or a `security.manage_roles` holder.
     */
    public function canManageBoard(object $user, TaskBoard $board): bool
    {
        if (!$this->canEditBoard($user, $board)) {
            return false;
        }

        $default = !$this->scopingEnabled || $this->hasManageRole($user) || $this->sameUser($user, $board->getCreator());
        $event   = new BoardAccessCheckEvent($user, $board, $default, BoardAccessCheckEvent::ACTION_MANAGE);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

        return $this->isBoardViewable($event);
    }

    /**
     * Since 2.5.0 (TB-13): whether the user may manage the board's columns (add, edit, reorder, delete; save, apply and
     * delete column sets) — in the UI, the Task API, a file import that would create columns and a provider sync. Needs
     * {@see self::canEditBoard()} and the {@see BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS} decision of
     * BOARD_ACCESS_CHECK, granted by default (the edit decision, as before 2.5.0); a deny sticks, and markReadOnly() on
     * that event refuses it as well.
     */
    public function canManageColumns(object $user, TaskBoard $board): bool
    {
        if (!$this->canEditBoard($user, $board)) {
            return false;
        }

        $event = new BoardAccessCheckEvent($user, $board, true, BoardAccessCheckEvent::ACTION_MANAGE_COLUMNS);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

        // Audit IM-I2: markReadOnly() on this decision refuses it too.
        return $this->isBoardViewable($event) && !$event->isReadOnly();
    }

    /**
     * Since 2.16.0 (Beacon spec 234, FR-061): the default of who manages a tenant's task templates — a holder of one of
     * `security.manage_roles` (the roles of the signed-in user), or anyone when `security.resource_scoping` is off. A host
     * that decides per tenant or per user sets `task_templates.permission`.
     */
    public function canManageTenantTemplates(object $user, ?string $tenantKey): bool
    {
        return !$this->scopingEnabled || $this->hasManageRole($user);
    }

    public function canViewTask(object $user, Task $task): bool
    {
        return $this->resolveTaskAccess($user, $task)->isGranted();
    }

    /**
     * Viewing is required; a listener may additionally mark the task read-only.
     */
    /**
     * Since 1.27.0 (audit GWd-M2): who may delete a manual time entry of a task they can edit — its author or a manager
     * of the board. An entry whose author no longer exists: a manager only. Without board scoping every editor is a
     * manager, as for the other board-level actions.
     */
    public function canRemoveTimeEntry(object $user, TaskTimeEntry $entry, Task $task): bool
    {
        return $this->canEditTimeEntry($user, $entry, $task);
    }

    /**
     * Since 2.12.0: who may change a manual time entry of a task they can edit (its `billable` flag) — the same people
     * as {@see self::canRemoveTimeEntry()}: its author or a manager of the board.
     */
    public function canEditTimeEntry(object $user, TaskTimeEntry $entry, Task $task): bool
    {
        return $this->canEditTask($user, $task) && ($this->sameUser($user, $entry->getUser()) || $this->canManageBoard($user, $task->getBoard()));
    }

    /**
     * Since 2.4.0 (spec 213): whether the user may log time on the task through the Task API. The host answers the
     * {@see TaskAccessCheckEvent::ACTION_TRACK_TIME} decision of TASK_ACCESS_CHECK: grant() allows it without the edit
     * right, deny() refuses it even to an editor. With no answer (or a grant marked read-only) the edit right decides,
     * so a host that does not look at the action keeps the behaviour it had before. View access is always required.
     */
    public function canTrackTime(object $user, Task $task): bool
    {
        if (!$this->canViewTask($user, $task)) {
            return false;
        }

        $event = new TaskAccessCheckEvent($user, $task, TaskAccessCheckEvent::ACTION_TRACK_TIME);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_ACCESS_CHECK);
        if ($event->isDenied()) {
            return false;
        }
        if ($event->isGranted() && !$event->isReadOnly()) {
            return true;
        }

        return $this->canEditTask($user, $task);
    }

    public function canEditTask(object $user, Task $task): bool
    {
        $event = $this->resolveTaskAccess($user, $task);

        return $event->isGranted() && !$event->isReadOnly();
    }

    /**
     * Since 2.22.0 (context menus): {@see self::canEditTask()} for the many tasks of one board page, with the board's own
     * decision (BOARD_ACCESS_CHECK) taken once instead of once per task: the returned closure answers exactly what
     * canEditTask() answers for a task of `$board` (TASK_ACCESS_CHECK first, then a participant or the board's viewers; a
     * read-only board keeps its tasks read-only). Build it per page; it holds nothing beyond that call.
     *
     * @return Closure(Task): bool
     */
    public function taskEditDecider(object $user, TaskBoard $board): Closure
    {
        $boardAccess = $this->resolveBoardAccess($user, $board);

        return function (Task $task) use ($user, $boardAccess): bool {
            $event = new TaskAccessCheckEvent($user, $task);
            $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_ACCESS_CHECK);
            if ($event->isDenied()) {
                return false;
            }
            if ($event->isGranted()) {
                return !$event->isReadOnly();
            }

            return ($this->isTaskParticipant($user, $task) || $this->isBoardViewable($boardAccess)) && !$boardAccess->isReadOnly();
        };
    }

    /**
     * Since 2.25.0 (header search): the default board rule for `$user`, read once ({@see BoardViewReach}): whether it opens
     * every board, the user's team ids, and whether a BOARD_ACCESS_CHECK listener could open more (true unless the
     * dispatcher says it has none). A search may filter on it in SQL only when no listener may grant.
     *
     * @param bool $manageRoles false leaves `security.manage_roles` out (see {@see self::canViewBoard()})
     */
    public function defaultBoardReach(object $user, bool $manageRoles = true): BoardViewReach
    {
        $dispatcher = $this->eventDispatcher;
        $listeners  = $dispatcher instanceof EventDispatcherInterface && (!$dispatcher instanceof SymfonyEventDispatcherInterface || $dispatcher->hasListeners(TaskBoardEvents::BOARD_ACCESS_CHECK));
        if (!$this->scopingEnabled || ($manageRoles && $this->hasManageRole($user))) {
            return new BoardViewReach(true, [], $listeners);
        }

        return new BoardViewReach(false, array_map(strval(...), array_keys($this->teamIdsOf($user))), $listeners);
    }

    /**
     * Since 2.25.0 (header search): {@see self::canViewBoard()} for many boards at once. The returned closure answers
     * exactly what canViewBoard() answers (creator, team, `security.manage_roles`, then BOARD_ACCESS_CHECK), but the
     * user's teams and manage roles are read once ({@see self::defaultBoardReach()}, or `$reach` when the caller already
     * has it) instead of once per board: each board costs its BOARD_ACCESS_CHECK event and no query. Build it per call;
     * it holds nothing beyond it.
     *
     * @param bool $manageRoles false leaves `security.manage_roles` out (see {@see self::canViewBoard()})
     *
     * @return Closure(TaskBoard): bool
     */
    public function boardViewDecider(object $user, bool $manageRoles = true, ?BoardViewReach $reach = null): Closure
    {
        return function (TaskBoard $board) use ($user, $manageRoles, &$reach): bool {
            $default = $this->sameUser($user, $board->getCreator());
            if (!$default) {
                $reach ??= $this->defaultBoardReach($user, $manageRoles);
                $team    = $board->getTeam();
                $default = $reach->all || ($team instanceof Team && in_array($team->getId(), $reach->teamIds, true));
            }
            $event = new BoardAccessCheckEvent($user, $board, $default);
            $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

            return $this->isBoardViewable($event);
        };
    }

    /**
     * Since 2.25.0 (header search): {@see self::canViewTask()} for many tasks at once. TASK_ACCESS_CHECK is dispatched
     * for each task (a deny hides it, a grant shows it); with no answer the task is visible when its board is, by
     * `$boardViewable` (for instance {@see self::boardViewDecider()}), or when the user takes part in it. Each board is
     * asked once. Build it per call; it holds nothing beyond it.
     *
     * @param Closure(TaskBoard): bool $boardViewable
     *
     * @return Closure(Task): bool
     */
    public function taskViewDecider(object $user, Closure $boardViewable): Closure
    {
        $boards = [];

        return function (Task $task) use ($user, $boardViewable, &$boards): bool {
            $event = new TaskAccessCheckEvent($user, $task);
            $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_ACCESS_CHECK);
            if ($event->isDenied()) {
                return false;
            }
            if ($event->isGranted()) {
                return true;
            }
            $board = $task->getBoard();
            $boards[$board->getId()] ??= $boardViewable($board);

            return $boards[$board->getId()] || $this->isTaskParticipant($user, $task);
        };
    }

    /**
     * The ids of the user's teams (membership resolver and TeamMember rows), as keys.
     *
     * @return array<string, true>
     */
    private function teamIdsOf(object $user): array
    {
        $ids = [];
        foreach ($this->teamMembershipResolver->resolveTeamIds($user) as $resolvedId) {
            $ids[(string) $resolvedId] = true;
        }
        $userId = $this->userId($user);
        if ($userId !== null) {
            foreach ($this->teamMemberRepository->findByUserId($userId) as $membership) {
                $ids[$membership->getTeam()->getId()] = true;
            }
        }

        return $ids;
    }

    /**
     * Since 2.23.0 (Beacon spec 242): whether the user may ask a question on the task. View access is required and an
     * archived task gets none. The {@see TaskAccessCheckEvent::ACTION_ASK_QUESTION} decision of TASK_ACCESS_CHECK: grant()
     * allows it, deny() refuses it (and sticks); with no answer it is the comment right — every viewer with
     * `comments.readers_can_comment` (the default), else {@see self::canEditTask()}.
     */
    public function canAskQuestion(object $user, Task $task): bool
    {
        if ($task->isArchived() || !$this->canViewTask($user, $task)) {
            return false;
        }

        return $this->questionDecision($user, $task, TaskAccessCheckEvent::ACTION_ASK_QUESTION, null)
            ?? ($this->readersCanComment || $this->canEditTask($user, $task));
    }

    /**
     * Since 2.23.0 (Beacon spec 242): {@see self::canAskQuestion()} for the tasks of one board page (the task menu's
     * *Ask a question…* entry), built on {@see self::taskEditDecider()}: the board's decision is taken once. Build it per
     * page; it holds nothing beyond that call. Every task it is asked about is one the page shows (view access).
     *
     * @return Closure(Task): bool
     */
    public function askQuestionDecider(object $user, TaskBoard $board): Closure
    {
        $canEdit = $this->taskEditDecider($user, $board);

        return fn (Task $task): bool => !$task->isArchived()
            && ($this->questionDecision($user, $task, TaskAccessCheckEvent::ACTION_ASK_QUESTION, null) ?? ($this->readersCanComment || $canEdit($task)));
    }

    /**
     * Since 2.23.0 (Beacon spec 242): whether the user may answer the question — view access to its task, the question not
     * withdrawn, then the {@see TaskAccessCheckEvent::ACTION_ANSWER_QUESTION} decision; with no answer, one of
     * `$subjectIds` (the recipient resolver's `subjectIdsOf()` for the user) is one of its recipients, byte for byte.
     *
     * @param list<string> $subjectIds
     */
    public function canAnswerQuestion(object $user, TaskQuestion $question, array $subjectIds): bool
    {
        $task = $question->getTask();
        if ($question->isWithdrawn() || !$this->canViewTask($user, $task)) {
            return false;
        }
        $decision = $this->questionDecision($user, $task, TaskAccessCheckEvent::ACTION_ANSWER_QUESTION, $question->getId());
        if ($decision !== null) {
            return $decision;
        }
        foreach ($subjectIds as $subjectId) {
            if ($question->recipient($subjectId) instanceof TaskQuestionRecipient) {
                return true;
            }
        }

        return false;
    }

    /**
     * Since 2.23.0 (Beacon spec 242): whether the user may withdraw the question — view access to its task, the question
     * open or answered, then the {@see TaskAccessCheckEvent::ACTION_WITHDRAW_QUESTION} decision; with no answer its asker
     * (by id) or a manager of the board ({@see self::canManageBoard()}).
     */
    public function canWithdrawQuestion(object $user, TaskQuestion $question): bool
    {
        $task = $question->getTask();
        if ($question->isWithdrawn() || !$this->canViewTask($user, $task)) {
            return false;
        }

        return $this->questionDecision($user, $task, TaskAccessCheckEvent::ACTION_WITHDRAW_QUESTION, $question->getId())
            ?? ($this->sameUser($user, $question->getAskedBy()) || $this->canManageBoard($user, $task->getBoard()));
    }

    /**
     * Since 2.23.0 (Beacon spec 242): whether the user may publish the decision of an answered question (the host's
     * publisher decides the target and may still hide the button): {@see self::canEditTask()}.
     */
    public function canPublishDecision(object $user, TaskQuestion $question): bool
    {
        return $question->isAnswered() && $this->canEditTask($user, $question->getTask());
    }

    /**
     * The host's decision on a question action: false denied, true granted, null when no listener answered (the bundle's
     * rule holds). Audit TE-M2: on the generic TASK_ACCESS_CHECK only `deny()` counts — a listener that grants every
     * action (written before 2.23) never widens the question rules; only TASK_QUESTION_ACCESS_CHECK may grant.
     */
    private function questionDecision(object $user, Task $task, string $action, ?string $questionId): ?bool
    {
        $generic = new TaskAccessCheckEvent($user, $task, $action, $questionId);
        $this->eventDispatcher?->dispatch($generic, TaskBoardEvents::TASK_ACCESS_CHECK);
        if ($generic->isDenied()) {
            return false;
        }
        $event = new TaskAccessCheckEvent($user, $task, $action, $questionId);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::TASK_QUESTION_ACCESS_CHECK);
        if ($event->isDenied()) {
            return false;
        }

        return $event->isGranted() && !$event->isReadOnly() ? true : null;
    }

    /**
     * Boards shown on the index: BOARD_LIST_QUERY listeners may replace the candidate list; the result is
     * always filtered through canViewBoard().
     *
     * @param list<TaskBoard> $candidates
     * @param string|null $tenantKey Tenant of the index (since 1.11), passed to the BOARD_LIST_QUERY listeners
     *
     * @return list<TaskBoard>
     */
    public function filterBoards(object $user, array $candidates, ?string $tenantKey = null): array
    {
        $event = new BoardListQueryEvent($user, $tenantKey);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_LIST_QUERY);
        $boards = $event->getOverrideList() ?? $candidates;

        return array_values(array_filter($boards, fn (TaskBoard $board): bool => $this->canViewBoard($user, $board)));
    }

    /**
     * Whether the user may delete a column set (since 1.11): its creator or a manage role, unless a
     * COLUMN_SET_ACCESS_CHECK listener decides otherwise.
     */
    public function canDeleteColumnSet(object $user, ColumnSet $set): bool
    {
        $creator = $set->getCreatedBy();
        $event   = new ColumnSetAccessCheckEvent($user, $set, $this->hasManageRole($user) || ($creator !== null && $this->sameUser($user, $creator)));
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::COLUMN_SET_ACCESS_CHECK);

        return $event->isGranted();
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

        if ($event->isGranted()) {
            return $event;
        }

        $boardAccess = $this->resolveBoardAccess($user, $task->getBoard());
        if ($this->isTaskParticipant($user, $task) || $this->isBoardViewable($boardAccess)) {
            $event->grant();
            // A read-only board keeps its tasks read-only (participants included).
            if ($boardAccess->isReadOnly()) {
                $event->markReadOnly();
            }
        }

        return $event;
    }

    /**
     * Dispatches BOARD_ACCESS_CHECK with the default decision; the event carries grant / deny / read-only.
     */
    private function resolveBoardAccess(object $user, TaskBoard $board, bool $manageRoles = true): BoardAccessCheckEvent
    {
        $event = new BoardAccessCheckEvent($user, $board, $this->defaultBoardDecision($user, $board, $manageRoles));
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::BOARD_ACCESS_CHECK);

        return $event;
    }

    private function isBoardViewable(BoardAccessCheckEvent $event): bool
    {
        return !$event->isDenied() && ($event->isGranted() || $event->isDefaultGranted());
    }

    private function defaultBoardDecision(object $user, TaskBoard $board, bool $manageRoles = true): bool
    {
        if (!$this->scopingEnabled || ($manageRoles && $this->hasManageRole($user))) {
            return true;
        }

        if ($this->sameUser($user, $board->getCreator())) {
            return true;
        }

        $team = $board->getTeam();

        return $team instanceof Team && $this->isTeamMember($user, $team);
    }

    private function isTaskParticipant(object $user, Task $task): bool
    {
        if ($this->sameUser($user, $task->getCreator())) {
            return true;
        }

        foreach ($task->getMembers() as $member) {
            if ($this->sameUser($user, $member->getUser())) {
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

        $userId = $this->userId($user);
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

    /**
     * Since 2.29.0: whether `$user` (not the signed-in user, unless it is them) holds one of `security.manage_roles`
     * ({@see UserRoleCheck}).
     */
    private function hasManageRole(object $user): bool
    {
        return (new UserRoleCheck($this->authorizationChecker, $this->tokenStorage))->hasAnyRole($user, $this->manageRoles);
    }

    /** `$b` null: an erased account (since 1.25, a board or task creator may be null) is nobody. */
    private function sameUser(object $a, ?object $b): bool
    {
        if ($b === null) {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $idA = $this->userId($a);

        return $idA !== null && $idA === $this->userId($b);
    }

    private function userId(object $user): ?string
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
