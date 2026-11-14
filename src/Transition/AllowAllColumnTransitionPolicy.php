<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Transition;

use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;

/**
 * Since 2.16.0 (spec 234): the default: every move is allowed, as before 2.16. Stateless.
 */
final readonly class AllowAllColumnTransitionPolicy implements ColumnTransitionPolicyInterface
{
    public function decide(ColumnTransition $transition): ColumnTransitionDecision
    {
        return ColumnTransitionDecision::allow();
    }
}
