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

    public function testSafeUrlOnlyAllowsHttpAndHttps(): void
    {
        self::assertSame('https://example.com/a?b=1', TaskBoardTwigExtension::safeUrl('https://example.com/a?b=1'));
        self::assertSame('http://gitlab.local/mr/1', TaskBoardTwigExtension::safeUrl(' http://gitlab.local/mr/1 '));
        self::assertSame('HTTPS://EXAMPLE.COM', TaskBoardTwigExtension::safeUrl('HTTPS://EXAMPLE.COM'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl('javascript:alert(document.cookie)'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl(' JavaScript:alert(1)'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl('//evil.example'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl(null));
    }

    public function testSafeColorOnlyAllowsHexColours(): void
    {
        self::assertSame('#3b82f6', TaskBoardTwigExtension::safeColor('#3b82f6'));
        self::assertSame('#FFF', TaskBoardTwigExtension::safeColor('#FFF'));
        self::assertSame('#11223344', TaskBoardTwigExtension::safeColor('#11223344'));
        self::assertNull(TaskBoardTwigExtension::safeColor('red;background:url(https://evil.example/x)'));
        self::assertNull(TaskBoardTwigExtension::safeColor("#fff\n"));
        self::assertNull(TaskBoardTwigExtension::safeColor('#12345'));
        self::assertNull(TaskBoardTwigExtension::safeColor(null));
    }

    public function testFiltersAreRegistered(): void
    {
        $names = array_map(static fn ($filter): string => $filter->getName(), (new TaskBoardTwigExtension('base.html.twig', 'custom'))->getFilters());

        self::assertSame(['nowo_task_board_safe_url', 'nowo_task_board_safe_color'], $names);
    }
}
