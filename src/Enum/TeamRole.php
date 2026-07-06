<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

enum TeamRole: string
{
    case Manager = 'manager';
    case Member  = 'member';
}
