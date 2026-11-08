<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

/**
 * Since 2.2.0 (spec 213): what a token may do. Not a chain: **Read** changes nothing and **My tasks** writes on its own
 * tasks, so ask {@see TaskApiCapability::allows()}, never compare levels.
 */
enum TaskApiLevel: string
{
    case MyTasks = 'my_tasks';
    case Read    = 'read';
    case Tasks   = 'tasks';
    case Full    = 'full';
}
