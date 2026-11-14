<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncDirectory;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\ValueObject\BoardColumnSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskChangeSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSyncDirectorySnapshot;

use function array_values;

/**
 * Since 2.15.0: copies the bundle's entities into the snapshot value objects of the framework-free ports. Reads getters
 * only (no lazy collection is initialised) and keeps no reference to the entity. Stateless.
 *
 * @internal
 */
final class Snapshots
{
    public static function board(TaskBoard $board): TaskBoardSnapshot
    {
        return new TaskBoardSnapshot(
            $board->getId(),
            $board->getSlug(),
            $board->getName(),
            $board->getTenantKey(),
            ActorId::of($board->getCreator()),
            $board->getCreatorName(),
            $board->isArchived(),
            $board->getCreatedAt(),
        );
    }

    /**
     * Since 2.16.0 (spec 234): `$last` is whether it is the board's last ("done") column.
     */
    /**
     * @param list<BoardColumn>|null $columns since 2.18.0: the board's columns by position the type is read among
     *                                        (default: the column's board's)
     */
    public static function column(BoardColumn $column, bool $last, ?array $columns = null): BoardColumnSnapshot
    {
        return new BoardColumnSnapshot(
            $column->getId(),
            $column->getBoard()->getId(),
            $column->getName(),
            $column->getPosition(),
            $column->isHumansOnly(),
            $last,
            ColumnTypes::of($column, $columns)->value,
        );
    }

    public static function task(Task $task): TaskSnapshot
    {
        $board = $task->getBoard();

        return new TaskSnapshot(
            $task->getId(),
            $board->getId(),
            $board->getTenantKey(),
            $task->getTitle(),
            $task->getNumber(),
            $task->getColumn()?->getId(),
            $task->getParent()?->getId(),
            ActorId::of($task->getCreator()),
            $task->getCreatorName(),
            $task->getPriority(),
            $task->isCompleted(),
            $task->isArchived(),
            $task->getStartAt(),
            $task->getDueAt(),
            array_values($task->getTags()),
            $task->getExternalId(),
            $task->getCreatedAt(),
            $task->getUpdatedAt(),
        );
    }

    public static function comment(TaskComment $comment): TaskCommentSnapshot
    {
        $task = $comment->getTask();

        return new TaskCommentSnapshot(
            $comment->getId(),
            $task->getId(),
            $task->getBoard()->getId(),
            ActorId::of($comment->getAuthor()),
            $comment->getAuthorName(),
            $comment->getBody(),
            $comment->getCause(),
            $comment->isDeleted(),
            $comment->getCreatedAt(),
            $comment->getEditedAt(),
        );
    }

    public static function change(TaskChangeHistory $entry): TaskChangeSnapshot
    {
        $task = $entry->getTask();

        return new TaskChangeSnapshot(
            $entry->getId(),
            $task->getId(),
            $task->getBoard()->getId(),
            ActorId::of($entry->getUser()),
            $entry->getUserName(),
            $entry->getChangeType(),
            $entry->getOldValue(),
            $entry->getNewValue(),
            $entry->getContext(),
            $entry->getCause(),
            $entry->getCreatedAt(),
        );
    }

    public static function directory(TaskSyncDirectory $directory): TaskSyncDirectorySnapshot
    {
        return new TaskSyncDirectorySnapshot(
            $directory->getId(),
            $directory->getProvider(),
            $directory->getScopeKey(),
            $directory->getWorkspaceId(),
        );
    }
}
