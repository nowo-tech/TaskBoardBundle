<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
        ]]);

        self::assertSame('task_board_', $config['table_prefix']);
        self::assertSame('/tools/task-board', $config['routes']['index']['path']);
        self::assertSame('@NowoTaskBoardBundle/layout.html.twig', $config['templates']['layout']);
        self::assertSame('tabler', $config['templates']['css_framework']);
    }

    public function testCustomLayoutTemplate(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
            'templates'  => ['layout' => 'platform/admin/layout.html.twig'],
        ]]);

        self::assertSame('platform/admin/layout.html.twig', $config['templates']['layout']);
    }

    public function testCssFrameworkAcceptedValues(): void
    {
        foreach (Configuration::CSS_FRAMEWORKS as $framework) {
            $config = (new Processor())->processConfiguration(new Configuration(), [[
                'user_class' => 'App\\Entity\\User',
                'templates'  => ['css_framework' => $framework],
            ]]);

            self::assertSame($framework, $config['templates']['css_framework']);
        }
    }

    public function testCssFrameworkRejectsInvalidValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
            'templates'  => ['css_framework' => 'invalid_framework'],
        ]]);
    }
}
