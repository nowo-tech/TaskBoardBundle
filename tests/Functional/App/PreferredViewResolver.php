<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;

/**
 * Since 1.24: a host "Default task view" preference; tests set the view.
 */
final class PreferredViewResolver implements TaskBoardDefaultViewResolverInterface
{
    public ?string $view = null;

    public function defaultView(object $user, TaskBoard $board): ?string
    {
        return $this->view;
    }
}
