<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/** Since 1.23: the levels of a provider's tree. */
enum ContainerType: string
{
    case Workspace = 'workspace';
    case Space     = 'space';
    case Folder    = 'folder';
    case List      = 'list';
}
