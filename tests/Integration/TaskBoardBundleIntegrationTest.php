<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TaskBoardBundleIntegrationTest extends TestCase
{
    public function testExtensionAliasMatchesBundleConfiguration(): void
    {
        $bundle = new TaskBoardBundle();
        self::assertSame('nowo_task_board', $bundle->getContainerExtension()->getAlias());
    }

    public function testContainerBuildsCoreServicesFromMinimalConfig(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        self::assertTrue($container->hasAlias(TaskBoardAccessCheckerInterface::class));
        self::assertTrue($container->hasDefinition(TaskBoardRouteLoader::class));
        self::assertTrue($container->hasAlias(TaskBoardRepositoryInterface::class));
        self::assertTrue($container->hasAlias(TaskRepositoryInterface::class));
    }
}
