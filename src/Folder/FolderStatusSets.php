<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Nowo\TaskBoardBundle\Configuration\BoardColumnsApplier;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Since 2.19.0 (Beacon spec 234, FR-034): a folder's shared status set. Every board in the folder has the set's columns
 * (name, order, type), and a change of the set is applied to every board in one transaction; a board whose tasks sit in a
 * column the set drops needs a column map for it (`columns_in_use` otherwise, and nothing is written).
 *
 * An edit of a column on one board of a sharing folder (UI or Task API) is carried to the set and its sibling boards after
 * that edit is saved ({@see self::propagateFrom()}, called by the column events); a sibling that would orphan tasks is
 * left as it is and logged — change the set through {@see self::setStatusSet()} with a column map to settle it.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final readonly class FolderStatusSets
{
    public function __construct(
        private TaskBoardFolderManager $folders,
        private TaskBoardRestructurePolicyInterface $policy,
        private TaskBoardRepositoryInterface $boards,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The folder's set, null when its boards keep their own columns (or the folder is unknown).
     *
     * @return list<BoardColumnSpec>|null
     */
    public function statusSet(string $folderId): ?array
    {
        return $this->folders->find($folderId)?->getStatusSet();
    }

    /**
     * Sets (or, with null, stops) the folder's shared status set and applies it to every board in the folder (archived
     * ones too). Needs the folder right and the restructure right on every board in it.
     *
     * @param list<BoardColumnSpec>|null $specs
     * @param array<string, array<string, string>> $columnMaps by board id: current column id or name => set column name
     *
     * @throws TaskBoardRestructureRefusedException `not_found`, `not_allowed`, `columns_in_use`, `invalid`, `quota`
     */
    public function setStatusSet(object $actor, string $folderId, ?array $specs, array $columnMaps = []): void
    {
        $folder = $this->folders->find($folderId) ?? throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
        $boards = $this->folders->boardsIn($folder, true);
        if (!$this->policy->mayManageFolders($actor, $folder->getTenantKey())) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
        }
        foreach ($boards as $board) {
            if (!$this->policy->mayRestructureBoard($actor, Snapshots::board($board))) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
            }
        }
        // Every board's refusal first: nothing changes, in memory either, unless every board can take the set.
        if ($specs !== null) {
            foreach ($boards as $board) {
                $this->folders->checkColumns($board, $specs, $columnMaps[$board->getId()] ?? []);
            }
        }
        // @igor-ignore - The closure runs at once inside transactional() and is never stored.
        $this->folders->transactional(function () use ($folder, $boards, $specs, $columnMaps): void {
            $folder->setStatusSet($specs);
            if ($specs !== null) {
                foreach ($boards as $board) {
                    $this->folders->applyColumns($board, $specs, $columnMaps[$board->getId()] ?? []);
                }
            }
        });
    }

    /**
     * Shares one board's columns with every board of its folder ({@see self::setStatusSet()} with that board's columns).
     *
     * @param array<string, array<string, string>> $columnMaps
     *
     * @throws TaskBoardRestructureRefusedException `not_found` when the board is not in that folder
     */
    public function shareFrom(object $actor, string $folderId, string $boardId, array $columnMaps = []): void
    {
        $board = $this->boards->findById($boardId);
        if (!$board instanceof TaskBoard || $board->getId() !== $boardId || $board->getFolderId() !== $folderId) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
        }
        $this->setStatusSet($actor, $folderId, BoardColumnsApplier::specsOf($board), $columnMaps);
    }

    /**
     * After a column edit saved on `$boardId`: when the board's folder shares its statuses, the set becomes the board's
     * columns and is applied to the other boards (`$renames`: old name => new name, so their tasks follow). Its own
     * transaction; a refusal is logged and changes nothing. No right is asked: the edit itself was allowed.
     *
     * @param array<string, string> $renames old name => new name
     * @param array<string, string> $deleted deleted column name => the id of the column its tasks went to on `$boardId`
     */
    public function propagateFrom(string $boardId, array $renames = [], array $deleted = []): void
    {
        $board  = $this->boards->findById($boardId);
        $folder = $board?->getFolder();
        if (!$board instanceof TaskBoard || !$folder instanceof TaskBoardFolder || $folder->getStatusSet() === null) {
            return;
        }
        $specs = BoardColumnsApplier::specsOf($board);
        foreach ($deleted as $name => $targetId) {
            foreach ($board->getColumns() as $column) {
                if ($column->getId() === $targetId) {
                    $renames[$name] = $column->getName();
                }
            }
        }
        try {
            foreach ($this->folders->boardsIn($folder, true) as $sibling) {
                if ($sibling->getId() !== $board->getId()) {
                    $this->folders->checkColumns($sibling, $specs, $renames);
                }
            }
            // @igor-ignore - The closure runs at once inside transactional() and is never stored.
            $this->folders->transactional(function () use ($folder, $board, $specs, $renames): void {
                $folder->setStatusSet($specs);
                foreach ($this->folders->boardsIn($folder, true) as $sibling) {
                    if ($sibling->getId() !== $board->getId()) {
                        $this->folders->applyColumns($sibling, $specs, $renames);
                    }
                }
            });
        } catch (Throwable $failure) {
            $this->logger->warning('TaskBoard: a column edit was not carried to the other boards of the folder', ['bundle' => 'nowo_task_board', 'board_id' => $boardId, 'folder_id' => $folder->getId(), 'reason' => $failure instanceof TaskBoardRestructureRefusedException ? $failure->reason : $failure::class]);
        }
    }
}
