<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Transition;

use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;

/**
 * Since 2.16.0 (spec 234, FR-014): whether a task may enter a column, and why not. Asked before anything changes by every move of
 * {@see TaskManager} (board drag, previous / next / done, `moveToColumn()`, a column change
 * in `update()`), by a create (the column the task starts in) and by every Task API operation that sets a status
 * (create, update, bulk, checklist items): board UI moves (channel `web`) included. Since audit PC also for every task a
 * column deletion moves (operation `column_delete`) and when the tags of a task that stays in its column change
 * (operation `retag`, `from` and `to` the same column). Imports do not ask it.
 *
 * Set `column_transition_policy` to a service implementing it. Default: {@see AllowAllColumnTransitionPolicy}. The
 * bundle ships {@see HumansOnlyColumnTransitionPolicy} as a ready-made opt-in. Framework-free: only bundle value
 * objects (REQ-ARCH-001 rule 3). Must keep no state between calls (worker mode).
 */
interface ColumnTransitionPolicyInterface
{
    /**
     * {@see ColumnTransitionDecision::allow()}, or {@see ColumnTransitionDecision::refuse()} with a code and a message.
     */
    public function decide(ColumnTransition $transition): ColumnTransitionDecision;
}
