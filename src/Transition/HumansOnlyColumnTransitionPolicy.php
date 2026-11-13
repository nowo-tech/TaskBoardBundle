<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Transition;

use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionVia;

use function in_array;

/**
 * Since 2.16.0 (spec 234, FR-015 / FR-017): an opt-in policy (`column_transition_policy:
 * Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy`). A task may not enter a column flagged *humans only*
 * through the Task API or an in-process caller (channels `api` and `mcp`) by any operation: a move, a create there, a
 * bulk item or a checklist item ticked into it (`STATUS_HUMANS_ONLY`). Strict by default; with
 * `humans_only_policy.allow_checklist: true` a checklist item (operation `checklist`) may still be ticked by them. A move
 * out of such a column and a move in the board UI (`web`) are allowed. A host policy can decorate it to add its own
 * rules (WIP limits, …). Stateless.
 */
final readonly class HumansOnlyColumnTransitionPolicy implements ColumnTransitionPolicyInterface
{
    /** The channels refused into a *humans only* column. */
    public const MACHINE_CHANNELS = [ColumnTransitionVia::API, ColumnTransitionVia::MCP];

    /**
     * @param bool $allowChecklist `humans_only_policy.allow_checklist`: let `api` / `mcp` tick checklist items into a
     *                             *humans only* column (default false: refused like any other move)
     */
    public function __construct(private bool $allowChecklist = false)
    {
    }

    public function decide(ColumnTransition $transition): ColumnTransitionDecision
    {
        if (
            $transition->to->humansOnly
            && in_array($transition->via->channel, self::MACHINE_CHANNELS, true)
            && !($this->allowChecklist && $transition->via->operation === ColumnTransitionVia::OP_CHECKLIST)
        ) {
            return ColumnTransitionDecision::refuse(ColumnTransitionDecision::CODE_HUMANS_ONLY, 'Only people can move tasks into this status');
        }

        return ColumnTransitionDecision::allow();
    }
}
