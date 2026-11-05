<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/** Since 1.23: where a run is. */
enum SyncPhase: string
{
    case Tasks     = 'tasks';
    case Deletions = 'deletions';
    /** Since 1.25: tracked time, window by window (after the deletions). */
    case TimeEntries = 'time_entries';
    case Done        = 'done';
}
