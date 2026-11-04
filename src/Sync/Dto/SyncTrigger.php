<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/** Since 1.23: what started a run. */
enum SyncTrigger: string
{
    case Manual   = 'manual';
    case Schedule = 'schedule';
    case Console  = 'console';
}
