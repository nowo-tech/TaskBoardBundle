<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => User::class,
        ]]);

        self::assertSame('task_board_', $config['table_prefix']);
        self::assertSame('/tools/task-board', $config['routes']['index']['path']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertArrayNotHasKey('layout', $config['templates']);
        self::assertArrayNotHasKey('css_framework', $config['templates']);
        self::assertArrayNotHasKey('firewall', $config);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame(['ROLE_USER'], $config['security']['access_roles']);
        self::assertTrue($config['security']['resource_scoping']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['manage_roles']);
    }

    public function testMaxTasksDefaultsToABoundAndRejectsZero(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);
        self::assertSame(Configuration::DEFAULT_MAX_TASKS, $config['board']['max_tasks']);
        self::assertSame(500, Configuration::DEFAULT_MAX_TASKS);

        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'board' => ['max_tasks' => 1000]]]);
        self::assertSame(1000, $config['board']['max_tasks']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'board' => ['max_tasks' => 0]]]);
    }

    /** Since 3.0.0: the removed options fail, naming their replacement; their siblings and an array without them pass. */
    public function testRemovedOptionsAreRefused(): void
    {
        foreach ([['templates' => ['layout' => 'x.html.twig']], ['templates' => ['css_framework' => 'tabler']], ['firewall' => 'admin']] as $removed) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class] + $removed]);
                self::fail('refused');
            } catch (InvalidConfigurationException $failure) {
                self::assertStringContainsString('was removed in 3.0', $failure->getMessage());
            }
        }
        self::assertSame(['templates' => ['index' => 'i.html.twig']], Configuration::refuseRemovedOptions(['templates' => ['index' => 'i.html.twig']]));
        self::assertSame(['templates' => 'not an array'], Configuration::refuseRemovedOptions(['templates' => 'not an array']));
    }
}
