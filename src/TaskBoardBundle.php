<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class TaskBoardBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoTaskBoardBundle';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
        // Since 2.15.0: the framework-free twins of the host ports (REQ-ARCH-001 rules 1 and 3).
        $container->addCompilerPass(new TaskBoardPortsPass());
        // Since 2.15.0 (database.private_entity_manager): the rollback middleware before DoctrineBundle's MiddlewaresPass,
        // and the bundle services moved onto the private manager once autowiring is done.
        $container->addCompilerPass(new PrivateEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new PrivateEntityManagerReferencesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new TaskBoardExtension();
        }

        return $this->extension;
    }
}
