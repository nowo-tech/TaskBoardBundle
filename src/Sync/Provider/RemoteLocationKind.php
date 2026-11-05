<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: the kind of a sync source.
 */
enum RemoteLocationKind: string
{
    case List   = 'list';
    case Folder = 'folder';
    case Space  = 'space';
}
