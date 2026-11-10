<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;

/**
 * Since 2.10.0 (DDD audit G4): the port of {@see BoardColumnManager}, which adds, edits, reorders and deletes columns.
 * A host's application layer depends on it and can double or decorate it; the container aliases it to the bundle's
 * class. The bundle's own controllers keep using the class, so a host decorator wraps the host's calls only.
 *
 * No access check (audit JY-L2): like the class, it trusts its caller. Before calling it the host checks what the bundle's
 * controllers check: `TaskBoardResourceAccessChecker` (`canEditTask` / `canEditBoard` / `canManageBoard` /
 * `canManageColumns` / `canTrackTime`), tenant ownership and plan, comment rights (`comments.readers_can_comment`), the
 * active-task quota (`TaskManager::activeTaskRefusal()` before a create or restore) and CSRF.
 */
interface BoardColumnManagerInterface
{
    public function add(TaskBoard $board, BoardColumnFormData $data, ?object $actor = null): BoardColumn;

    /**
     * Non-string entries (e.g. nested arrays from columnOrder[0][]=…) are ignored.
     *
     * @param array<mixed> $orderedColumnIds
     */
    public function reorder(TaskBoard $board, array $orderedColumnIds, ?object $actor = null): void;

    public function update(BoardColumn $column, BoardColumnFormData $data, ?object $actor = null): void;

    /**
     * Deletes a column (since 1.11): its tasks move to the end of the board's first remaining column (completed when
     * that column is the last one, reopened otherwise); the last column of a board is never deleted (false).
     *
     * @throws TaskReopenRefusedException since 1.29.0, when the host's limit refuses the tasks it would reopen: nothing changes
     */
    public function delete(BoardColumn $column, ?object $actor = null): bool;
}
