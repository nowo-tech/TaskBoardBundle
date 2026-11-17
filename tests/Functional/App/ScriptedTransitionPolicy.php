<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Closure;
use Nowo\TaskBoardBundle\Transition\ColumnTransitionPolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;

/**
 * Since 2.16.0 (spec 234): a host policy for the UI tests: a column named "Blocked" refuses every channel, with a host
 * message (or none, when `$message` is empty); every transition asked is kept.
 */
final class ScriptedTransitionPolicy implements ColumnTransitionPolicyInterface
{
    /** @var list<ColumnTransition> */
    public static array $asked = [];

    public static string $message = 'A blocked task needs a reason';

    /** Since 2.21.0: runs while the policy is asked (a concurrent write between the checks and the lock); null: none. */
    public static ?Closure $meanwhile = null;

    public function decide(ColumnTransition $transition): ColumnTransitionDecision
    {
        self::$asked[] = $transition;
        if (self::$meanwhile instanceof Closure) {
            (self::$meanwhile)($transition);
        }

        return $transition->to->name === 'Blocked' ? ColumnTransitionDecision::refuse('BLOCK_REASON_REQUIRED', self::$message) : ColumnTransitionDecision::allow();
    }
}
