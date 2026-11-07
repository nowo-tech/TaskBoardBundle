<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiPlanGate;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Spec 213: a host that would open boards by accident does not get a container.
 */
final class TaskApiExtensionTest extends TestCase
{
    public function testEnabledWithoutAnAuthenticatorStopsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('api.hooks.authenticator');

        $this->load(['api' => ['enabled' => true]]);
    }

    public function testAnEmptyAuthenticatorStopsTheBuildToo(): void
    {
        $this->expectException(LogicException::class);

        $this->load(['api' => ['enabled' => true, 'hooks' => ['authenticator' => '']]]);
    }

    public function testEnabledWithAllowUnauthenticatedStopsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('allow_unauthenticated');

        $this->load(['api' => ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth']], 'security' => ['allow_unauthenticated' => true]]);
    }

    public function testNoScopingNeedsTheHostToSaySoItDecides(): void
    {
        try {
            $this->load(['api' => ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth']], 'security' => ['resource_scoping' => false]]);
            self::fail('LogicException expected');
        } catch (LogicException $exception) {
            self::assertStringContainsString('host_decides_access', $exception->getMessage());
        }

        $container = $this->load(['api' => ['enabled' => true, 'host_decides_access' => true, 'hooks' => ['authenticator' => 'app.auth']], 'security' => ['resource_scoping' => false]]);
        self::assertSame('/api/tasks', $container->getParameter('nowo_task_board.api.prefix'));
    }

    public function testUnsetHooksAreTheDeniersAndSetOnesAreTheHosts(): void
    {
        $defaults = $this->load(['api' => ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth']]]);
        self::assertSame(DenyingTaskApiPlanGate::class, (string) $defaults->getAlias(TaskApiPlanGateInterface::class));
        self::assertFalse($defaults->hasAlias(TaskApiIdempotencyStoreInterface::class));

        $hosts = $this->load(['api' => ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth', 'plan_gate' => 'app.plan', 'idempotency_store' => 'app.store']]]);
        self::assertSame('app.plan', (string) $hosts->getAlias(TaskApiPlanGateInterface::class));
        self::assertSame('app.store', (string) $hosts->getAlias(TaskApiIdempotencyStoreInterface::class));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function badPrefixes(): iterable
    {
        yield 'no slash' => ['api/tasks'];
        yield 'trailing slash' => ['/api/tasks/'];
        yield 'root' => ['/'];
        yield 'a variable' => ['/api/{tenant}'];
        yield 'not a string' => [true];
    }

    #[DataProvider('badPrefixes')]
    public function testABadPrefixIsAConfigurationError(mixed $prefix): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['api' => ['prefix' => $prefix]]);
    }

    public function testOffRegistersNothingEvenWithHooksSet(): void
    {
        $container = $this->load(['api' => ['enabled' => false, 'hooks' => ['authenticator' => 'app.auth']]]);

        self::assertFalse($container->hasParameter('nowo_task_board.api.prefix'));
        foreach ([...array_keys($container->getDefinitions()), ...array_keys($container->getAliases())] as $id) {
            self::assertStringNotContainsString('TaskBoardBundle\\Api\\', $id);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.bundles', ['SecurityBundle' => stdClass::class]);
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        (new TaskBoardExtension())->load([['user_class' => stdClass::class] + $config], $container);

        return $container;
    }
}
