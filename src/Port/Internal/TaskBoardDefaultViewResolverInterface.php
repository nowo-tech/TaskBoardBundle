<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;

/**
 * Since 1.24 (`board.default_view_resolver`): the view a board opens in for a user when the plain board route is
 * requested without an explicit view (e.g. a "Default task view" preference of the host). The bundle redirects a
 * main GET request of the board route to the list or Gantt route of the same board; `?view=board`, an XHR request
 * or a link from another page of the same board (same-origin `Referer`) keeps the board view.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskBoardDefaultViewPolicyInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskBoardDefaultViewResolverInterface
{
    public const VIEW_BOARD = 'board';
    public const VIEW_LIST  = 'list';
    public const VIEW_GANTT = 'gantt';
    /** Since 2.1.0 (spec 214, FR-024): honoured with `calendar.enabled`; otherwise it keeps the board view. */
    public const VIEW_CALENDAR = 'calendar';

    /** The views a resolver may answer. */
    public const VIEWS = [self::VIEW_BOARD, self::VIEW_LIST, self::VIEW_GANTT, self::VIEW_CALENDAR];

    /**
     * `board`, `list`, `gantt` or `calendar`; null (or any other value) keeps the board view. Called after the board access
     * checks, so `$board` is one the user may open.
     */
    public function defaultView(object $user, TaskBoard $board): ?string;
}
