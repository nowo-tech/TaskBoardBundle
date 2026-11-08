<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use InvalidArgumentException;
use Symfony\Contracts\Service\ResetInterface;

use function array_pop;
use function end;
use function mb_strlen;

/**
 * Since 2.3.0 (spec 216, FR-013): says why the writes inside a call happen, e.g. an automation rule. Every history row
 * written inside {@see self::run()} — by `TaskManager`, `TaskMemberAssigner`, `TaskDependencyManager`, the copier, any
 * service that records through {@see TaskChangeRecorder} — keeps the cause in `TaskChangeHistory::$cause`, and the
 * listeners of the events dispatched inside the call read it from {@see self::current()} (a comment, which has no history
 * row, is marked that way). Calls nest; the innermost cause wins. Reset between the requests of a worker.
 */
final class TaskChangeContext implements ResetInterface
{
    public const MAX_LENGTH = 191;

    /** @var list<string> */
    private array $stack = [];

    /**
     * Runs `$write` with `$cause` (1 to 191 characters, e.g. "rule:42") and returns what it returns.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function run(string $cause, callable $write): mixed
    {
        if ($cause === '' || mb_strlen($cause) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A change cause has 1 to 191 characters.');
        }
        // @igor-ignore - Scoped to the call: popped in finally, and cleared by reset() between the requests of a worker.
        $this->stack[] = $cause;
        try {
            return $write();
        } finally {
            // @igor-ignore - Scoped to the call (see above).
            array_pop($this->stack);
        }
    }

    /** The cause of the writes running now; null outside {@see self::run()}. */
    public function current(): ?string
    {
        $stack = $this->stack;

        return $stack === [] ? null : end($stack);
    }

    public function reset(): void
    {
        // @igor-ignore - Cleared between the requests of a worker.
        $this->stack = [];
    }
}
