<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/** Since 1.23: Initial (no watermark yet), Incremental (tasks updated since the watermark) or Full. */
enum SyncMode: string
{
    case Initial     = 'initial';
    case Incremental = 'incremental';
    case Full        = 'full';
}
