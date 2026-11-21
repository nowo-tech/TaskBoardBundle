<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ActorId;

use function array_values;

/**
 * Since 2.29.0: copies entities into the views of the id-based host ports. Reads getters only (no lazy collection is
 * initialised) and keeps no reference to the entity. Stateless.
 *
 * @internal
 */
final class Views
{
    public static function task(Task $task): TaskView
    {
        $board = $task->getBoard();

        return new TaskView(
            $task->getId(),
            $board->getId(),
            $board->getTenantKey(),
            $task->getTitle(),
            $task->getDescription(),
            $task->getNumber(),
            $task->getColumn()?->getId(),
            $task->getParent()?->getId(),
            ActorId::of($task->getCreator()),
            $task->getPriority(),
            $task->getEstimatedMinutes(),
            $task->getColor(),
            array_values($task->getTags()),
            $task->getStartAt(),
            $task->getDueAt(),
            $task->getCompletedAt(),
            $task->getArchivedAt(),
            $task->getTotalTimeSeconds(),
            $task->getRevision(),
            $task->getCreatedAt(),
            $task->getUpdatedAt(),
        );
    }

    public static function board(TaskBoard $board): BoardView
    {
        return new BoardView(
            $board->getId(),
            $board->getSlug(),
            $board->getName(),
            $board->getTenantKey(),
            ActorId::of($board->getCreator()),
            $board->getArchivedAt(),
            $board->getCreatedAt(),
        );
    }
}
