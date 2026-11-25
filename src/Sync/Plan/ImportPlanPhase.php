<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2): where an item of a running plan is (the plan cursor is the item position and this phase).
 * The board exists before the run starts (it is created when the plan is started), so the runner links, then syncs.
 */
enum ImportPlanPhase: string
{
    case Link = 'link';
    case Sync = 'sync';
    case Done = 'done';
}
