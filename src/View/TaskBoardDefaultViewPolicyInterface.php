<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskBoardDefaultViewResolverInterface` (the 2.14 interface, removed in 3.0): the view a
 * board opens in for a user, given a {@see TaskBoardSnapshot}. Answers one of
 * {@see TaskBoardDefaultViewResolverInterface::VIEWS}; null (or any other value) keeps the board view.
 *
 * Set `board.default_view_resolver` to a service implementing it.
 */
interface TaskBoardDefaultViewPolicyInterface
{
    public function defaultView(object $user, TaskBoardSnapshot $board): ?string;
}
