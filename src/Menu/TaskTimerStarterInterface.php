<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Menu;

use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimerStarter;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.22.0: "Start timer" of the task menu. Registered only with the TimeTrack integration
 * ({@see TimeTrackTaskTimerStarter}); without an implementation the entry is not
 * offered and the route refuses it. Implementations must be stateless.
 */
interface TaskTimerStarterInterface
{
    /** Whether the time tracker is there (the menu offers "Start timer" only then). */
    public function isAvailable(): bool;

    public function start(object $user, Task $task): TaskTimerStart;
}
