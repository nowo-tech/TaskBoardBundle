<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Closure;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 3.3.0 (audit ZCF-L1 / L3): a restructure policy for the tests (used when a test sets `restructure.policy`).
 * Every question is allowed unless `$deny` answers true for it; `$meanwhile` runs while the policy is asked (another
 * writer's change between a read and the write that follows), then is dropped (once).
 */
final class ScriptedRestructurePolicy implements TaskBoardRestructurePolicyInterface
{
    /** @var (Closure(string): bool)|null asked with "folders" or the board id */
    public static ?Closure $deny = null;

    /** @var (Closure(): void)|null */
    public static ?Closure $meanwhile = null;

    /** How many questions to let pass before `$meanwhile` runs. */
    public static int $after = 0;

    public static function reset(): void
    {
        self::$deny      = null;
        self::$meanwhile = null;
        self::$after     = 0;
    }

    public function mayManageFolders(object $user, ?string $tenantKey): bool
    {
        return $this->answer('folders');
    }

    public function mayRestructureBoard(object $user, TaskBoardSnapshot $board): bool
    {
        return $this->answer($board->id);
    }

    private function answer(string $subject): bool
    {
        if (self::$meanwhile instanceof Closure) {
            if (self::$after-- <= 0) {
                $meanwhile       = self::$meanwhile;
                self::$meanwhile = null;
                $meanwhile();
            }
        }

        return !self::$deny instanceof Closure || !(self::$deny)($subject);
    }
}
