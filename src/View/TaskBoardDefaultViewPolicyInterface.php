<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see TaskBoardDefaultViewResolverInterface}: the view a
 * board opens in for a user, given a {@see TaskBoardSnapshot}. Answers one of
 * {@see TaskBoardDefaultViewResolverInterface::VIEWS}; null (or any other value) keeps the board view.
 *
 * Set `board.default_view_resolver` to a service implementing it. A service that also implements the deprecated interface
 * is asked through that one until 3.0.
 */
interface TaskBoardDefaultViewPolicyInterface
{
    public function defaultView(object $user, TaskBoardSnapshot $board): ?string;
}
