<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewPolicyInterface;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;

final readonly class TwinDefaultViewPolicy implements TaskBoardDefaultViewPolicyInterface
{
    public function __construct(private TaskBoardDefaultViewResolverInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function defaultView(object $user, TaskBoardSnapshot $board): ?string
    {
        return $this->legacy->defaultView($user, $this->lookup->board($board->id));
    }
}
