<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\View;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Default {@see TaskBoardDefaultViewResolverInterface} (since 1.24): every board opens in the board view.
 */
final readonly class NullTaskBoardDefaultViewResolver implements TaskBoardDefaultViewResolverInterface
{
    public function defaultView(object $user, TaskBoard $board): ?string
    {
        return null;
    }
}
