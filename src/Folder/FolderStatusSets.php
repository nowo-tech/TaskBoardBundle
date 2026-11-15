<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Configuration\BoardColumnsApplier;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskColumnFollow;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ColumnTasks;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Transition\AllowAllColumnTransitionPolicy;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_values;
use function count;

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
        // Since 2.19.0 (audit PW-D1-L3): asked for the tasks a column change moves on the other boards of the folder.
        private ColumnTransitionPolicyInterface $transitions = new AllowAllColumnTransitionPolicy(),
        private ?EntityManagerInterface $entityManager = null,
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
     * @param ColumnTransitionVia|null $via since 2.20.0: the caller's channel for the column transition policy (none: `web`)
     *
     * @throws TaskBoardRestructureRefusedException `not_found`, `not_allowed`, `columns_in_use`, `invalid`, `quota`
     * @throws ColumnTransitionRefusedException since 2.20.0, when the policy refuses a task the set moves: nothing changes
     */
    public function setStatusSet(object $actor, string $folderId, ?array $specs, array $columnMaps = [], ?ColumnTransitionVia $via = null): void
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
            // Since 2.20.0 (audit PW-D2-L1): every task the set moves, asked of the column transition policy first.
            foreach ($boards as $board) {
                $this->folders->assertColumnTransitions($board, $specs, $columnMaps[$board->getId()] ?? [], $actor, $via);
            }
        }
        // @igor-ignore - The closure runs at once inside transactional() and is never stored.
        $this->folders->transactional(function () use ($folder, $boards, $specs, $columnMaps, $actor): void {
            $folder->setStatusSet($specs);
            if ($specs !== null) {
                foreach ($boards as $board) {
                    $this->folders->applyColumns($board, $specs, $columnMaps[$board->getId()] ?? [], $actor);
                }
            }
        });
    }

    /**
     * Since 2.19.0 (audit PW-H1): a column edit on a board whose folder shares its statuses changes every board of the
     * folder, so it needs what {@see self::setStatusSet()} needs: the folder right and the restructure right on every
     * board in the folder. Refuses `not_allowed` (`folder`: its id) otherwise; nothing to ask on any other board.
     *
     * @throws TaskBoardRestructureRefusedException
     */
    public function assertMayChangeColumns(object $actor, TaskBoard $board): void
    {
        $folder = $board->getFolder();
        if ($folder instanceof TaskBoardFolder && $folder->getStatusSet() !== null && !$this->mayChangeSharedColumns($actor, $folder)) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED, ['folder' => $folder->getId()]);
        }
    }

    /**
     * Since 2.19.0 (audit PW-D1-L3): a column change that moves tasks on the board (a deletion: `$moves` deleted column
     * name => the name of the column its tasks go to) moves the tasks of the same column on every other board of a
     * sharing folder too. The column transition policy is asked for each of them, through the caller's channel, before
     * anything is written; the first refusal stops the change.
     *
     * @param array<string, string> $moves
     *
     * @throws ColumnTransitionRefusedException
     */
    public function assertSiblingTransitions(object $actor, TaskBoard $board, array $moves, ColumnTransitionVia $via): void
    {
        $folder = $board->getFolder();
        if (!$folder instanceof TaskBoardFolder || $folder->getStatusSet() === null || $moves === []) {
            return;
        }
        foreach ($this->folders->boardsIn($folder, true) as $sibling) {
            if ($sibling->getId() === $board->getId()) {
                continue;
            }
            $columns = ColumnTypes::ordered($sibling);
            $byName  = [];
            foreach ($columns as $column) {
                $byName[$column->getName()] = $column;
            }
            $last = $columns === [] ? null : $columns[count($columns) - 1];
            foreach ($moves as $from => $to) {
                $source = $byName[$from] ?? null;
                $target = $byName[$to] ?? null;
                // A sibling of the set has both columns; a board the set has not reached yet asks nothing.
                $tasks = $source instanceof BoardColumn && $target instanceof BoardColumn ? ColumnTasks::of($this->entityManager, $source) : [];
                foreach ($tasks as $task) {
                    $transition = new ColumnTransition(Snapshots::board($sibling), Snapshots::task($task), Snapshots::column($source, $source === $last, $columns), Snapshots::column($target, $target === $last, $columns), $actor, ActorId::of($actor), $via, array_values($task->getTags()));
                    $decision   = $this->transitions->decide($transition);
                    if (!$decision->allowed) {
                        throw new ColumnTransitionRefusedException($transition, $decision);
                    }
                }
            }
        }
    }

    private function mayChangeSharedColumns(object $actor, TaskBoardFolder $folder): bool
    {
        if (!$this->policy->mayManageFolders($actor, $folder->getTenantKey())) {
            return false;
        }
        foreach ($this->folders->boardsIn($folder, true) as $board) {
            if (!$this->policy->mayRestructureBoard($actor, Snapshots::board($board))) {
                return false;
            }
        }

        return true;
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
     * transaction; a refusal is logged and changes nothing. Since 2.19.0 (audit PW-H1), with an actor, nothing is carried
     * unless it has the folder right and the restructure right on every board of the folder (logged otherwise); the tasks
     * the change moves or completes get their history rows and events ({@see TaskColumnFollow}).
     *
     * @param array<string, string> $renames old name => new name
     * @param array<string, string> $deleted deleted column name => the id of the column its tasks went to on `$boardId`
     */
    public function propagateFrom(string $boardId, array $renames = [], array $deleted = [], ?object $actor = null): void
    {
        $board  = $this->boards->findById($boardId);
        $folder = $board?->getFolder();
        if (!$board instanceof TaskBoard || !$folder instanceof TaskBoardFolder || $folder->getStatusSet() === null) {
            return;
        }
        // Audit PW-H1: never rewrite a board the actor may not restructure. BoardColumnManager refuses such an edit before
        // it is saved; this is the second line, for a caller that edits columns another way.
        if ($actor !== null && !$this->mayChangeSharedColumns($actor, $folder)) {
            $this->logger->warning('TaskBoard: a column edit was not carried to the other boards of the folder', ['bundle' => 'nowo_task_board', 'board_id' => $boardId, 'folder_id' => $folder->getId(), 'reason' => TaskBoardRestructureRefusedException::NOT_ALLOWED]);

            return;
        }
        // Audit PW-D1-I3: history rows and the task's last writer reference the actor: an entity of this manager.
        $actor = $actor === null ? null : $this->folders->managedActor($actor);
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
            $this->folders->transactional(function () use ($folder, $board, $specs, $renames, $actor): void {
                $folder->setStatusSet($specs);
                foreach ($this->folders->boardsIn($folder, true) as $sibling) {
                    if ($sibling->getId() !== $board->getId()) {
                        $this->folders->applyColumns($sibling, $specs, $renames, $actor);
                    }
                }
            });
        } catch (Throwable $failure) {
            $this->logger->warning('TaskBoard: a column edit was not carried to the other boards of the folder', ['bundle' => 'nowo_task_board', 'board_id' => $boardId, 'folder_id' => $folder->getId(), 'reason' => $failure instanceof TaskBoardRestructureRefusedException ? $failure->reason : $failure::class]);
        }
    }
}
