<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\EraseCursor;
use Nowo\TaskBoardBundle\Dto\EraseProgress;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\InvalidEraseCursorException;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Since 2.11.0 (DDD audit G4): the port of {@see TaskBoardEraser}, which empties or deletes a board in resumable, signed
 * slices and erases a task with its subtasks. Tracked time is always kept (detached). A host's application layer depends
 * on it and can double or decorate it; the container aliases it to the bundle's class. The bundle's own API keeps using
 * the class, so a host decorator wraps the host's calls only.
 *
 * **It deletes data, and unlike the other ports it checks access itself:** every method refuses with
 * {@see AccessDeniedException} unless `TaskBoardResourceAccessChecker::canManageBoard($actor, $board)` allows it, before
 * anything is touched, and a resumed cursor must be the signed cursor of the same board, actor and operation. A host
 * implementation or decorator must keep both checks (call the bundle's class, or check `canManageBoard()` and the cursor
 * itself); the port never relaxes them. The caller still checks what the bundle's API checks besides: that the board
 * belongs to the current tenant and its plan, and CSRF for a form.
 */
interface TaskBoardEraserInterface
{
    /**
     * Deletes every task of the board (time kept, detached); the board and its columns stay.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     * @throws InvalidEraseCursorException when `$cursor` is not the signed cursor of this board, actor and operation
     */
    public function emptyBoard(TaskBoard $board, object $actor, int $batchSize = 200, ?EraseCursor $cursor = null): EraseProgress;

    /**
     * Deletes every task (time kept, detached), then the columns and the board.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     * @throws InvalidEraseCursorException when `$cursor` is not the signed cursor of this board, actor and operation
     */
    public function deleteBoard(TaskBoard $board, object $actor, int $batchSize = 200, ?EraseCursor $cursor = null): EraseProgress;

    /**
     * Deletes one task and its subtasks, leaf-first; time is kept, detached. Returns how many were deleted.
     *
     * @throws AccessDeniedException when the actor may not manage the board
     * @throws TaskLinkedToProviderException when the task or a subtask follows a provider: nothing is deleted
     */
    public function eraseTask(Task $task, object $actor): int;
}
