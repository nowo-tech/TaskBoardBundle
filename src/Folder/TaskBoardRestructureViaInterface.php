<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;

/**
 * Since 2.20.0 (audit PW-D2-L1): the companion of {@see TaskBoardRestructureInterface} for a caller that knows its channel
 * (the Task API, MCP, a host's agent). The 2.19 port keeps its signature, so a host class that implements it is not
 * broken; the bundle's `TaskBoardRestructurer` implements both, and a caller uses this one when the instance supports it:
 *
 *     $restructure instanceof TaskBoardRestructureViaInterface
 *         ? $restructure->moveBoardToFolderVia($actor, $boardId, $folderId, $columnMap, $via)
 *         : $restructure->moveBoardToFolder($actor, $boardId, $folderId, $columnMap);
 */
interface TaskBoardRestructureViaInterface
{
    /**
     * {@see TaskBoardRestructureInterface::moveBoardToFolder()} with the caller's channel: into a folder that shares its
     * statuses, every task the column map moves to another status is asked of the column transition policy through `$via`
     * before anything is written; the first refusal changes nothing.
     *
     * @param array<string, string> $columnMap
     *
     * @throws TaskBoardRestructureRefusedException
     * @throws ColumnTransitionRefusedException
     */
    public function moveBoardToFolderVia(object $actor, string $boardId, ?string $folderId, array $columnMap, ColumnTransitionVia $via): void;
}
