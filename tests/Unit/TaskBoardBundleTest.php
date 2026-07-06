<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TaskBoardBundleTest extends TestCase
{
    public function testTranslationDomain(): void
    {
        self::assertSame('NowoTaskBoardBundle', TaskBoardBundle::TRANSLATION_DOMAIN);
    }

    public function testBuildRegistersTwigCompilerPass(): void
    {
        $container = new ContainerBuilder();
        (new TaskBoardBundle())->build($container);

        self::assertNotEmpty($container->getCompilerPassConfig()->getBeforeOptimizationPasses());
    }

    public function testGetContainerExtensionReturnsTaskBoardExtension(): void
    {
        $bundle = new TaskBoardBundle();

        self::assertInstanceOf(TaskBoardExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
    }
}
