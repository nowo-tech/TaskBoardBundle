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
 * out of such a column, a move in the board UI (`web`) and a tag change of a task already in it (operation `retag`) are
 * allowed. Since audit PC-M1 the Task API also refuses `api` / `mcp` callers the column changes that would lift the gate
 * (the flag itself, deleting a flagged column, moving a flagged last column away from the end) with the same answer. A host policy can decorate it to add its own
 * rules (WIP limits, …). Stateless.
 */
final readonly class HumansOnlyColumnTransitionPolicy implements ColumnTransitionPolicyInterface
{
    /** The channels refused into a *humans only* column. */
    public const MACHINE_CHANNELS = [ColumnTransitionVia::API, ColumnTransitionVia::MCP];

    /** Since 2.16.0 (audit PC-M1): the refusal's message, the same on every path that refuses a *humans only* change. */
    public const MESSAGE = 'Only people can move tasks into this status';

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
            // Audit PC-L2: a retag leaves the task in its column; it does not enter it.
            && $transition->via->operation !== ColumnTransitionVia::OP_RETAG
            && in_array($transition->via->channel, self::MACHINE_CHANNELS, true)
            && !($this->allowChecklist && $transition->via->operation === ColumnTransitionVia::OP_CHECKLIST)
        ) {
            return ColumnTransitionDecision::refuse(ColumnTransitionDecision::CODE_HUMANS_ONLY, self::MESSAGE);
        }

        return ColumnTransitionDecision::allow();
    }
}
