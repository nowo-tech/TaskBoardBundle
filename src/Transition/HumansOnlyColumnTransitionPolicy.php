<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Transition;

use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;

use function in_array;

/**
 * Since 2.16.0 (spec 234, FR-015 / FR-017): an opt-in policy (`column_transition_policy:
 * Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy`): a task may not enter a column flagged *humans only*
 * through the Task API or an in-process caller (channels `api` and `mcp`), neither by a move nor by a create there:
 * `STATUS_HUMANS_ONLY`. A move out of such a column and a move in the board UI (`web`) are allowed. Ticking a checklist
 * item (operation `checklist`) is allowed too: it is a checkbox, not a status the person decides. A host policy can
 * decorate it to add its own rules (WIP limits, …). Stateless.
 */
final readonly class HumansOnlyColumnTransitionPolicy implements ColumnTransitionPolicyInterface
{
    /** The channels refused into a *humans only* column. */
    public const MACHINE_CHANNELS = [ColumnTransitionVia::API, ColumnTransitionVia::MCP];

    public function decide(ColumnTransition $transition): ColumnTransitionDecision
    {
        if (
            $transition->to->humansOnly
            && $transition->via->operation !== ColumnTransitionVia::OP_CHECKLIST
            && in_array($transition->via->channel, self::MACHINE_CHANNELS, true)
        ) {
            return ColumnTransitionDecision::refuse(ColumnTransitionDecision::CODE_HUMANS_ONLY, 'Only people can move tasks into this status');
        }

        return ColumnTransitionDecision::allow();
    }
}
