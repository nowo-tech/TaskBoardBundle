<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: what tracked time a token may read. `admin` = every member's entries; `member` = its own only.
 */
enum TimeAccessMode: string
{
    case Admin  = 'admin';
    case Member = 'member';
}
