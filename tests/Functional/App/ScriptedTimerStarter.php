<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Menu\TaskTimerStart;
use Nowo\TaskBoardBundle\Menu\TaskTimerStarterInterface;

/**
 * Since 2.22.0: the time tracker of the functional kernel's task menu (`__timer: true`), answering the scripted outcome and
 * keeping the tasks it was asked to start.
 */
final class ScriptedTimerStarter implements TaskTimerStarterInterface
{
    public static TaskTimerStart $outcome = TaskTimerStart::Started;

    /** @var list<string> */
    public static array $started = [];

    public static function reset(): void
    {
        self::$outcome = TaskTimerStart::Started;
        self::$started = [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function start(object $user, Task $task): TaskTimerStart
    {
        if (self::$outcome === TaskTimerStart::Started) {
            self::$started[] = $task->getId();
        }

        return self::$outcome;
    }
}
