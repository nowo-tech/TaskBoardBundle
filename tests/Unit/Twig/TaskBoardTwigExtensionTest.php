<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;

final class TaskBoardTwigExtensionTest extends TestCase
{
    public function testGetGlobals(): void
    {
        $extension = new TaskBoardTwigExtension('@NowoTaskBoardBundle/layout.html.twig', 'tabler');

        self::assertSame([
            'nowo_task_board_layout'        => '@NowoTaskBoardBundle/layout.html.twig',
            'nowo_task_board_css_framework' => 'tabler',
        ], $extension->getGlobals());
    }

    public function testGetGlobalsUsesConfiguredValues(): void
    {
        $extension = new TaskBoardTwigExtension('base.html.twig', 'custom');

        self::assertSame([
            'nowo_task_board_layout'        => 'base.html.twig',
            'nowo_task_board_css_framework' => 'custom',
        ], $extension->getGlobals());
    }
}
