<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0 (Beacon D-20261112-01): the tasks of several boards for one viewer: a page, the workload per person and
 * the rows of an export. Values in, values out (REQ-ARCH-001): a DDD host calls it with its own ids.
 *
 * Unlike the command ports, this one checks access itself, on every call: the boards are those of
 * `multi_board.scope_provider` for the viewer, each re-checked with `TaskBoardResourceAccessChecker::canViewBoard()` for
 * the user whose id the viewer carries (an unknown user id reads nothing). A filter can only narrow that set.
 * With tenancy each board's tenant must resolve for that user too. Call it for the signed-in user: the token's
 * `security.manage_roles` apply only when the viewer's id is the token user's id.
 */
interface MultiBoardTaskListInterface
{
    /**
     * The boards the viewer may read here, in the provider's order (empty: nothing to show).
     *
     * @return list<MultiBoardBoard>
     */
    public function boards(MultiBoardViewer $viewer): array;

    /** One page (1-based) of the matching tasks, with the filter choices. */
    public function page(MultiBoardViewer $viewer, MultiBoardTaskFilter $filter, int $page, int $perPage): MultiBoardTaskPage;

    /** The matching tasks grouped by assignee. */
    public function workload(MultiBoardViewer $viewer, MultiBoardTaskFilter $filter): MultiBoardWorkload;

    /**
     * Every matching task in the list's order, read in batches while the result is iterated, at most `$maxRows`
     * (default `multi_board.export.max_rows`). Null: the viewer reads no board.
     */
    public function export(MultiBoardViewer $viewer, MultiBoardTaskFilter $filter, ?int $maxRows = null): ?MultiBoardExportRows;
}
