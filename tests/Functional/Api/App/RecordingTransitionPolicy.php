<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Closure;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;

use function in_array;

/**
 * Since 2.16.0 (spec 234): the host's column transition policy in the tests: the bundled humans-only rule, plus a
 * scripted WIP rule (a column name refused with `WIP_LIMIT` and a message), recording every transition asked.
 */
final class RecordingTransitionPolicy implements ColumnTransitionPolicyInterface
{
    /** @var list<ColumnTransition> */
    public static array $asked = [];

    /** @var list<string> column names refused with WIP_LIMIT */
    public static array $wipFull = [];

    /** `humans_only_policy.allow_checklist` of the bundled rule. */
    public static bool $allowChecklist = false;

    /**
     * Audit PC: a stateful host rule (it reads the database), asked first; null from it falls through to the rules above.
     *
     * @var (Closure(ColumnTransition): ?ColumnTransitionDecision)|null
     */
    public static ?Closure $decider = null;

    public static function reset(): void
    {
        self::$asked          = [];
        self::$wipFull        = [];
        self::$allowChecklist = false;
        self::$decider        = null;
    }

    public function decide(ColumnTransition $transition): ColumnTransitionDecision
    {
        self::$asked[] = $transition;
        if (self::$decider instanceof Closure && ($decision = (self::$decider)($transition)) instanceof ColumnTransitionDecision) {
            return $decision;
        }
        if (in_array($transition->to->name, self::$wipFull, true)) {
            return ColumnTransitionDecision::refuse('WIP_LIMIT', 'Only one task per repository in ' . $transition->to->name);
        }

        return (new HumansOnlyColumnTransitionPolicy(self::$allowChecklist))->decide($transition);
    }
}
