<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/** Since 1.23: where a run is. */
enum SyncPhase: string
{
    case Tasks     = 'tasks';
    case Deletions = 'deletions';
    case Done      = 'done';
}
