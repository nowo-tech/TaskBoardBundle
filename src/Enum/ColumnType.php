<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Since 2.19.0 (Beacon spec 234, FR-033): what a column means, as ClickUp's status types. A task in a `done` or `closed`
 * column is completed; a board may have up to {@see self::MAX_CLOSED} `closed` columns (*hecho*, *cancelada*).
 */
enum ColumnType: string
{
    case Open   = 'open';
    case Custom = 'custom';
    case Done   = 'done';
    case Closed = 'closed';

    public const MAX_CLOSED = 2;

    public function isDone(): bool
    {
        return $this === self::Done || $this === self::Closed;
    }
}
