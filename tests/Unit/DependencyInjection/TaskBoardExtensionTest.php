<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class TaskBoardExtensionTest extends TestCase
{
    public function testLoadSetsParametersAndAliases(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TaskBoardExtension())->load([[
            'user_class' => 'App\\Entity\\User',
        ]], $container);

        self::assertSame('App\\Entity\\User', $container->getParameter('nowo_task_board.user_class'));
        self::assertSame('task_board_tasks', $container->getParameter('nowo_task_board.tasks_table'));
        self::assertTrue($container->hasAlias('nowo_task_board.task_provider'));
        self::assertTrue($container->hasAlias('nowo_task_board.team_context_provider'));
        self::assertTrue($container->hasAlias(TaskRepositoryInterface::class));
        self::assertTrue($container->hasDefinition(TaskBoardTaskProvider::class));
    }

    public function testPrependRegistersFrameworkAssetsAndDoctrineMapping(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FrameworkExtension());
        $container->registerExtension(new DoctrineExtension());

        (new TaskBoardExtension())->prepend($container);

        self::assertNotEmpty($container->getExtensionConfig('framework'));
        self::assertNotEmpty($container->getExtensionConfig('doctrine'));
    }

    public function testPrependNoOpWithoutOptionalExtensions(): void
    {
        $container = new ContainerBuilder();

        (new TaskBoardExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_task_board', (new TaskBoardExtension())->getAlias());
    }

    public function testLoadWithCustomAccessCheckerAndTeamResolver(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setDefinition('app.access_checker', new Definition(stdClass::class));
        $container->setDefinition('app.team_resolver', new Definition(stdClass::class));

        (new TaskBoardExtension())->load([[
            'user_class'               => 'App\\Entity\\User',
            'team_membership_resolver' => 'app.team_resolver',
            'security'                 => ['access_checker' => 'app.access_checker'],
        ]], $container);

        self::assertSame('app.access_checker', (string) $container->getAlias(TaskBoardAccessCheckerInterface::class));
        self::assertSame('app.team_resolver', (string) $container->getAlias(TaskBoardTeamMembershipResolverInterface::class));
    }

    public function testLoadRegistersDefaultAccessCheckerAndNullTeamResolver(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TaskBoardExtension())->load([['user_class' => 'App\\Entity\\User']], $container);

        self::assertTrue($container->hasDefinition('nowo_task_board.access_checker.default'));
        self::assertTrue($container->hasDefinition('nowo_task_board.team_resolver.null'));
    }
}
