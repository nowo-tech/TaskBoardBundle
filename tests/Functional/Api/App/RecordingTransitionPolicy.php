<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

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

    public static function reset(): void
    {
        self::$asked   = [];
        self::$wipFull = [];
    }

    public function decide(ColumnTransition $transition): ColumnTransitionDecision
    {
        self::$asked[] = $transition;
        if (in_array($transition->to->name, self::$wipFull, true)) {
            return ColumnTransitionDecision::refuse('WIP_LIMIT', 'Only one task per repository in ' . $transition->to->name);
        }

        return (new HumansOnlyColumnTransitionPolicy())->decide($transition);
    }
}
