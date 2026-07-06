<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class TaskBoardBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoTaskBoardBundle';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new TaskBoardExtension();
        }

        return $this->extension;
    }
}
