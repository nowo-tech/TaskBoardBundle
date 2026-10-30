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
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveNamedArgumentsPass;
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

    /** 1.17.1: every named argument of the bundle's own service definitions exists on the class (1.17.0 shipped one that did not). */
    public function testNamedArgumentsMatchTheConstructors(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        (new ResolveClassPass())->process($container);
        $container->removeDefinition('Nowo\\TaskBoardBundle\\Controller\\TaskBoardManageController');
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->getClass() === null || !class_exists($definition->getClass())) {
                $container->removeDefinition($id);
            }
        }
        (new ResolveNamedArgumentsPass())->process($container);

        $twig = $container->getDefinition(TaskBoardTwigExtension::class)->getArguments();
        self::assertContains('%nowo_task_board.templates.filters_collapse%', $twig);
        self::assertContains('%nowo_task_board.templates.filters_form_class%', $twig);
        self::assertSame('Nowo\\TaskBoardBundle\\Text\\PlainTaskTextRenderer', (string) $container->getAlias('Nowo\\TaskBoardBundle\\Text\\TaskTextRendererInterface'));
        self::assertNotContains('%nowo_task_board.templates.filters_collapse%', $container->getDefinition(TaskBoardRouteLoader::class)->getArguments());
    }
}
