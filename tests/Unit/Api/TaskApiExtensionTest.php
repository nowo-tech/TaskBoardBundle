<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Api;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiPlanGate;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Idempotency\DoctrineTaskApiIdempotencyStore;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
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
        self::assertSame(DoctrineTaskApiIdempotencyStore::class, (string) $defaults->getAlias(TaskApiIdempotencyStoreInterface::class), 'the bundle\'s own store');

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

    public function testSettingTheOptionOffEqualsNotSettingIt(): void
    {
        $unset = $this->load([]);
        $off   = $this->load(['api' => ['enabled' => false, 'prefix' => '/elsewhere', 'hooks' => ['authenticator' => 'app.auth', 'rate_limiter' => 'app.limiter']]]);

        self::assertSame(array_keys($unset->getDefinitions()), array_keys($off->getDefinitions()));
        self::assertSame(array_keys($unset->getAliases()), array_keys($off->getAliases()));
        self::assertSame($unset->getParameterBag()->all(), $off->getParameterBag()->all());
        self::assertEquals($unset->getDefinition(TaskBoardRouteLoader::class)->getArguments(), $off->getDefinition(TaskBoardRouteLoader::class)->getArguments());
    }

    public function testTheApiTableAndItsQueryFunctionArePrependedOnlyWhenTheyAreNeeded(): void
    {
        $doctrine = static function (array $config): string {
            $container = new ContainerBuilder();
            $container->registerExtension(new DoctrineExtension());
            $container->setParameter('kernel.debug', false);
            $container->prependExtensionConfig('nowo_task_board', ['user_class' => stdClass::class] + $config);
            (new TaskBoardExtension())->prepend($container);

            return (string) json_encode($container->getExtensionConfig('doctrine'));
        };

        $off = $doctrine([]);
        self::assertStringNotContainsString('TaskBoardApi', $off);
        self::assertStringNotContainsString('NOWO_TB_JSON_TEXT', $off);
        $on = $doctrine(['api' => ['enabled' => true]]);
        self::assertStringContainsString('TaskBoardApi', $on);
        self::assertStringContainsString('NOWO_TB_JSON_TEXT', $on);
        $replaced = $doctrine(['api' => ['enabled' => true, 'hooks' => ['idempotency_store' => 'app.store']]]);
        self::assertStringNotContainsString('TaskBoardApi', $replaced, 'a host store needs no table of the bundle');
        self::assertStringContainsString('NOWO_TB_JSON_TEXT', $replaced);
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
