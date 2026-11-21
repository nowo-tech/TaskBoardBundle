<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskBoardAccessDeniedException;
use Nowo\TaskBoardBundle\Exception\TaskBoardQuotaRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Nowo\TaskBoardBundle\View\TaskView;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Since 2.29.0 (DDD-host checklist B1, REQ-ARCH-001 rules 1 and 3): the bundle's writes for a host by id. The acting user
 * is a {@see UserRef}, every resource an id, and a call returns a {@see TaskView} or the new row's id, never an entity.
 * Implemented by {@see TaskCommands} on top of the bundle's own services (same history rows, events, column transition
 * policy, host limits and validation as the pages).
 *
 * - **Access, at every call** (user actions): the user is looked up again by id, must pass the global gate and the
 *   resource rule the page applies ({@see TaskBoardAccessInterface}): edit the board to create a task or add a column
 *   (`canManageColumns` for columns), edit the task for every task change, comment rights for a comment, track time for a
 *   time entry, manage the board to delete. An unknown user, an unknown id (looked up byte for byte) and a refusal all
 *   throw {@see TaskBoardAccessDeniedException}, with one message, so an id never confirms that something exists.
 * - **One write unit per call**: one transaction (a savepoint inside the application's transaction), the task events
 *   held until it commits and dropped when it rolls back. A failure rolls everything back and leaves no closed manager.
 * - **Concurrency** (REQ-RUNTIME-003): a task write locks the task's row first (`SELECT ... FOR UPDATE`) and works on the
 *   task as committed; with `$expectedRevision` (the {@see TaskView::$revision} the caller read) a task changed meanwhile
 *   is refused with {@see TaskChangedException} (409) and nothing is written. A lock conflict with a concurrent write
 *   (deadlock, lock wait timeout) throws {@see TransientStorageFailure}: nothing was written, retry.
 * - **System calls** ({@see self::adjustTotalTime()}, {@see self::clearTimeEntrySnapshotsForUser()}) take no user and
 *   check no access: they are for the host's own bookkeeping (a timer, an erasure).
 *
 * Stateless (FrankenPHP worker mode with kernel reset off): nothing is kept between calls.
 */
interface TaskCommandsInterface
{
    /**
     * Creates a task on a board (`$data`: title, description, priority, column, estimate, due and start dates, colour,
     * tags), optionally as a subtask of `$parentId` (same board) and optionally already completed (`$completed`: through
     * the done column when the board has columns, in place otherwise; one write unit with the creation).
     *
     * @throws TaskBoardAccessDeniedException
     * @throws TaskBoardQuotaRefusedException when the host's plan refuses a new task (QUOTA_CHECK): nothing is created
     * @throws TaskReopenRefusedException when the host's active-task limit refuses one more task: nothing is created
     * @throws ColumnTransitionRefusedException when the column transition policy refuses the column
     * @throws TaskTreeRefusedException when the subtask would be too deep
     * @throws InvalidArgumentException on an invalid title or a parent of another board
     * @throws TransientStorageFailure
     */
    public function createTask(UserRef $creator, string $boardId, TaskFormData $data, ?string $parentId = null, bool $completed = false): TaskView;

    /**
     * Saves the task form's fields (a column change is a move, as on the page).
     *
     * @throws TaskBoardAccessDeniedException
     * @throws TaskChangedException when `$expectedRevision` is not the task's revision
     * @throws TransientStorageFailure
     */
    public function updateTask(UserRef $actor, string $taskId, TaskFormData $data, ?int $expectedRevision = null): TaskView;

    /**
     * Moves the task to a column (null: no column) at a position, renumbering that column.
     *
     * @throws TaskBoardAccessDeniedException
     * @throws TaskChangedException when `$expectedRevision` is not the task's revision
     * @throws TransientStorageFailure
     */
    public function moveTask(UserRef $actor, string $taskId, ?string $columnId, int $position, ?int $expectedRevision = null): TaskView;

    /**
     * Completes the task: to the done column on a board with columns, in place on a board without any (Beacon's
     * fallback). Nothing changes when it already is.
     *
     * @throws TaskBoardAccessDeniedException
     * @throws TransientStorageFailure
     */
    public function completeTask(UserRef $actor, string $taskId): TaskView;

    /**
     * @throws TaskBoardAccessDeniedException
     * @throws TransientStorageFailure
     */
    public function archiveTask(UserRef $actor, string $taskId): TaskView;

    /**
     * @throws TaskBoardAccessDeniedException
     * @throws TaskReopenRefusedException when the host's limit refuses an active task more
     * @throws TransientStorageFailure
     */
    public function restoreTask(UserRef $actor, string $taskId): TaskView;

    /**
     * Deletes the task and its subtasks (their time entries are kept, detached). Needs the board's manage right.
     *
     * @throws TaskBoardAccessDeniedException
     * @throws TaskLinkedToProviderException when the task follows a provider: nothing is deleted
     * @throws TransientStorageFailure
     *
     * @return int how many tasks were deleted
     */
    public function deleteTask(UserRef $actor, string $taskId): int;

    /**
     * Adds a user to the task in a role (the assignee provider decides who may be a member). Adding the same user in the
     * same role again changes nothing and returns the existing member.
     *
     * @throws TaskBoardAccessDeniedException also when `$member` names nobody
     * @throws UnassignableUserException when the assignee provider refuses the user
     * @throws TransientStorageFailure
     *
     * @return string the member row's id
     */
    public function addMember(UserRef $actor, string $taskId, UserRef $member, TaskMemberRole $role = TaskMemberRole::Assignee): string;

    /**
     * @throws TaskBoardAccessDeniedException
     * @throws TransientStorageFailure
     *
     * @return bool false when the task has no such member
     */
    public function removeMember(UserRef $actor, string $taskId, string $memberId): bool;

    /**
     * Adds a comment (`comments.readers_can_comment` decides whether a reader may). `$mentionIds` are user ids.
     *
     * @param list<string> $mentionIds
     *
     * @throws TaskBoardAccessDeniedException
     * @throws InvalidArgumentException on a blank or too long body
     * @throws TransientStorageFailure
     *
     * @return string the comment's id
     */
    public function addComment(UserRef $author, string $taskId, string $body, array $mentionIds = []): string;

    /**
     * Attaches a link (URL, document, merge request...).
     *
     * @throws TaskBoardAccessDeniedException
     * @throws InvalidArgumentException on an invalid link
     * @throws TransientStorageFailure
     *
     * @return string the link's id
     */
    public function addLink(UserRef $actor, string $taskId, TaskLinkFormData $data): string;

    /**
     * Links one of the host's own objects (`TaskLink::forHostObject()`): a type and an id of the host. Linking the
     * same object again returns the existing link.
     *
     * @throws TaskBoardAccessDeniedException
     * @throws TransientStorageFailure
     *
     * @return string the link's id
     */
    public function addHostObjectLink(UserRef $actor, string $taskId, string $objectType, string $objectId, ?string $label = null): string;

    /**
     * Adds a dependency between two tasks the actor may edit (same rules as the page: no self-dependency, no cycle).
     *
     * @throws TaskBoardAccessDeniedException also when the actor may not edit the other task
     * @throws InvalidArgumentException on an invalid dependency
     * @throws TransientStorageFailure
     *
     * @return string the dependency's id
     */
    public function addDependency(UserRef $actor, string $taskId, string $otherTaskId, TaskDependencyType $type): string;

    /**
     * Adds a document (title and Markdown content) to the task, at `$position` (null: after the last one).
     *
     * @throws TaskBoardAccessDeniedException
     * @throws InvalidArgumentException on a blank title
     * @throws TransientStorageFailure
     *
     * @return string the document's id
     */
    public function addDocument(UserRef $author, string $taskId, string $title, string $content, ?int $position = null): string;

    /**
     * Logs manual time of `$user` on the task (`$user` must be allowed to track time on it).
     *
     * @throws TaskBoardAccessDeniedException
     * @throws InvalidArgumentException on minutes out of range, or a closed period
     * @throws TransientStorageFailure
     *
     * @return string the time entry's id
     */
    public function logTime(UserRef $user, string $taskId, int $minutes, DateTimeImmutable $loggedAt, ?string $note = null): string;

    /**
     * Adds a column to the board, at `$position` (0-based; null: last). Needs the board's column right.
     *
     * @throws TaskBoardAccessDeniedException
     * @throws InvalidArgumentException on an invalid name
     * @throws TransientStorageFailure
     *
     * @return string the column's id
     */
    public function addColumn(UserRef $actor, string $boardId, BoardColumnFormData $data, ?int $position = null): string;

    /**
     * Puts the board's columns in this order (every column id of the board, exactly once).
     *
     * @param list<string> $orderedColumnIds
     *
     * @throws TaskBoardAccessDeniedException
     * @throws InvalidArgumentException when the list is not the board's columns
     * @throws TransientStorageFailure
     */
    public function reorderColumns(UserRef $actor, string $boardId, array $orderedColumnIds): void;

    /**
     * **System call.** Adds `$deltaSeconds` (negative to subtract) to the task's tracked total in one atomic UPDATE,
     * clamped at 0, so two concurrent adjustments never lose one. Returns the new total; null for an unknown task.
     *
     * @throws TransientStorageFailure
     */
    public function adjustTotalTime(string $taskId, int $deltaSeconds): ?int;

    /**
     * **System call** (erasure). Clears the task title and external id snapshots of the user's manual time entries whose
     * task was deleted (detached entries); the time itself is kept. Batched and idempotent: a second call changes
     * nothing. Returns how many entries changed.
     *
     * @throws InvalidArgumentException when the id cannot be matched exactly
     * @throws TransientStorageFailure
     */
    public function clearTimeEntrySnapshotsForUser(string $userId): int;
}
