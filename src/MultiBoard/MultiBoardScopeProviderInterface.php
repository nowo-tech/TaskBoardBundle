<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0 (Beacon D-20261112-01, `multi_board.scope_provider`): the boards one viewer may read in the multi-board
 * task list ("All tasks" of an organisation). Host port with bundle-owned value objects only (REQ-ARCH-001 rule 3).
 *
 * Deny by default: return {@see MultiBoardScope::none()} for anyone the host does not recognise. Return only boards the
 * viewer can already read elsewhere; the bundle re-checks every one with `TaskBoardResourceAccessChecker::canViewBoard()`
 * (and its BOARD_ACCESS_CHECK listeners) and drops the others, so this list can narrow access but never widen it.
 *
 * Called once per page or export, never per row. Must not keep the viewer in a property (FrankenPHP worker mode,
 * REQ-RUNTIME-002): resolve per call.
 */
interface MultiBoardScopeProviderInterface
{
    public function readableBoards(MultiBoardViewer $viewer): MultiBoardScope;
}
