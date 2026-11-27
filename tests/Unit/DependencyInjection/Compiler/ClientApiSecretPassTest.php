<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TaskBoardBundle\ClientApi\TaskClientCursor;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\ClientApiSecretPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;

/**
 * Since 3.4.1 (audit ZCS-v-I4): with the client API on, an empty kernel.secret fails the build.
 */
final class ClientApiSecretPassTest extends TestCase
{
    public function testWithoutTheClientApiAnySecretIsFine(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.secret', '');
        (new ClientApiSecretPass())->process($container);
        self::assertFalse($container->hasDefinition(TaskClientCursor::class));
    }

    public function testASecretOrAnEnvVarPasses(): void
    {
        foreach (['s3cret', '%env(APP_SECRET)%'] as $secret) {
            $container = $this->clientApi();
            $container->setParameter('kernel.secret', $secret);
            (new ClientApiSecretPass())->process($container);
            self::assertTrue($container->hasDefinition(TaskClientCursor::class));
        }
    }

    public function testAnEmptySecretFails(): void
    {
        foreach (['', '  ', null, ['not', 'a', 'string']] as $secret) {
            $container = $this->clientApi();
            $container->setParameter('kernel.secret', $secret);
            try {
                (new ClientApiSecretPass())->process($container);
                self::fail('An empty secret must fail.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('non-empty kernel.secret', $exception->getMessage());
            }
        }
    }

    public function testAMissingSecretFails(): void
    {
        $this->expectException(LogicException::class);
        (new ClientApiSecretPass())->process($this->clientApi());
    }

    private function clientApi(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(TaskClientCursor::class, new Definition(TaskClientCursor::class));

        return $container;
    }
}
