<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig globals for the TaskBoard manage Web UI (REQ-UI-001).
 */
final class TaskBoardTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly string $layoutTemplate,
        private readonly string $cssFramework,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'nowo_task_board_layout'        => $this->layoutTemplate,
            'nowo_task_board_css_framework' => $this->cssFramework,
        ];
    }
}
