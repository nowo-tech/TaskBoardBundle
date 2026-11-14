<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

/**
 * Since 2.18.0 (Beacon spec 234, FR-031 / FR-045): the restructure service — folders of a tenant, boards in folders, tasks
 * moved between the boards of one tenant. A host's restructure command depends on it (public alias of
 * {@see TaskBoardRestructurer}). Framework-free: ids in, VOs out (REQ-ARCH-001 rules 1 and 3).
 *
 * Every call:
 * - never crosses tenants: a folder, board or task of another tenant than the one the call works in is `not_found`, the
 *   same refusal as an unknown id;
 * - asks {@see TaskBoardRestructurePolicyInterface} for `$actor` (default: the manage right);
 * - is atomic: one transaction, rolled back on any failure, and the entity manager left open (worker mode with kernel
 *   reset off); events are dispatched after the commit.
 *
 * @throws TaskBoardRestructureRefusedException before anything is written
 */
interface TaskBoardRestructureInterface
{
    /**
     * The folders of a tenant (`null`: of boards without a tenant), by position then name. Reads only: no right is asked
     * (the host lists them for whoever may see the tenant).
     *
     * @return list<TaskBoardFolderSnapshot>
     */
    public function folders(?string $tenantKey): array;

    /** One folder, or null when unknown; the caller compares its `tenantKey` with the tenant it works in. */
    public function folder(string $folderId): ?TaskBoardFolderSnapshot;

    /**
     * @param string|null $description Markdown
     * @param int|null $position null: after the tenant's last folder
     */
    public function createFolder(object $actor, ?string $tenantKey, string $name, ?string $description = null, ?int $position = null): TaskBoardFolderSnapshot;

    /** Replaces name, description and position (read the snapshot first to keep a value). */
    public function updateFolder(object $actor, string $folderId, string $name, ?string $description, int $position): TaskBoardFolderSnapshot;

    /** Only an empty folder (no board, archived ones included): `not_empty` otherwise. */
    public function deleteFolder(object $actor, string $folderId): void;

    /**
     * Puts a board in a folder of its own tenant, or out of any (`$folderId` null). Into a folder with a shared status set
     * (since 2.18.0, FR-034) the board takes the set's columns: `$columnMap` (current column id or name => a column name of
     * the set) moves the tasks of a column the set does not have; without it such a column is `columns_in_use`.
     *
     * @param array<string, string> $columnMap
     */
    public function moveBoardToFolder(object $actor, string $boardId, ?string $folderId, array $columnMap = []): void;

    /**
     * Moves tasks, each with its whole subtree, to another board of the same tenant; a subtask becomes a top-level task
     * there. Ids, numbers (tenant scope), comments, time entries (TimeTrack keeps pointing at the same task id), tags,
     * documents, links and history are kept.
     *
     * Column, for each task: `$columnMap` by the source column's id, then by its name, naming a target column by id or by
     * name; else the target's column of the same name; else the target's first open column (a completed task: its last
     * column). Names compare without case, accents or edge spaces.
     *
     * @param list<string> $taskIds
     * @param array<string, string> $columnMap source column id or name => target column id or name
     */
    public function moveTasks(object $actor, array $taskIds, string $targetBoardId, array $columnMap = []): TaskBoardMoveResult;
}
