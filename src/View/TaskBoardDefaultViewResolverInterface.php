<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.24 (`board.default_view_resolver`): the view a board opens in for a user when the plain board route is
 * requested without an explicit view (e.g. a "Default task view" preference of the host). The bundle redirects a
 * main GET request of the board route to the list or Gantt route of the same board; `?view=board`, an XHR request
 * or a link from another page of the same board (same-origin `Referer`) keeps the board view.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see TaskBoardDefaultViewPolicyInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
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
