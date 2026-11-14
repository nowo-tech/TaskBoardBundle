<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\TaskApiAuthFailure;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetIdsInterface;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Port\TaskApiRequestAuthenticatorGate;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorProviderInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisioningInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 2.15.0: the build-time half of the selection rule. A deprecated implementation keeps its alias; a twin-only one is
 * put behind the {@see Ports} factory; a class implementing neither stops the build; a class the build cannot know is
 * left to the factory, which fails closed at run time.
 */
final class TaskBoardPortsPassTest extends TestCase
{
    public function testDeprecatedImplementationKeepsItsAlias(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.auth', LegacyAuthenticator::class);
        $container->setAlias(TaskApiAuthenticatorInterface::class, 'app.auth');

        (new TaskBoardPortsPass())->process($container);

        self::assertTrue($container->hasAlias(TaskApiAuthenticatorInterface::class));
        self::assertSame('app.auth', (string) $container->getAlias(TaskApiAuthenticatorInterface::class));
    }

    public function testTwinImplementationIsServedThroughTheGate(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.auth', TwinAuthenticator::class)->setPublic(true);
        $container->setAlias(TaskApiAuthenticatorInterface::class, 'app.auth')->setPublic(true);

        (new TaskBoardPortsPass())->process($container);

        $definition = $container->getDefinition(TaskApiAuthenticatorInterface::class);
        self::assertSame([Ports::class, 'apiAuthenticator'], $definition->getFactory());
        self::assertEquals([new Reference('app.auth')], $definition->getArguments());
        self::assertTrue($definition->isPublic());

        $container->compile();
        self::assertInstanceOf(TaskApiRequestAuthenticatorGate::class, $container->get(TaskApiAuthenticatorInterface::class));
    }

    public function testServiceDefinedUnderTheInterfaceIdMovesToAnInnerId(): void
    {
        $container = new ContainerBuilder();
        $container->register(TaskBoardTargetsInterface::class, TwinTargets::class);
        $container->register(TaskBoardRepositoryInterface::class, stdClass::class);

        (new TaskBoardPortsPass())->process($container);

        self::assertTrue($container->hasDefinition(TaskBoardTargetsInterface::class . '.inner'));
        self::assertSame(TwinTargets::class, $container->getDefinition(TaskBoardTargetsInterface::class . '.inner')->getClass());
        self::assertEquals(
            [new Reference(TaskBoardTargetsInterface::class . '.inner'), new Reference(TaskBoardRepositoryInterface::class)],
            $container->getDefinition(TaskBoardTargetsInterface::class)->getArguments(),
        );
        self::assertFalse($container->getDefinition(TaskBoardTargetsInterface::class)->isPublic());
    }

    public function testReferencePortsGetTheBoardsRepository(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.first_board', TwinProvisioning::class);
        $container->setAlias(TaskBoardPortsPass::PORT_PREFIX . 'first_board_provisioner', 'app.first_board');

        (new TaskBoardPortsPass())->process($container);

        $definition = $container->getDefinition(TaskBoardPortsPass::PORT_PREFIX . 'first_board_provisioner');
        self::assertSame(FirstBoardProvisionerInterface::class, $definition->getClass());
        self::assertSame([Ports::class, 'firstBoardProvisioner'], $definition->getFactory());
        self::assertEquals([new Reference('app.first_board'), new Reference(TaskBoardRepositoryInterface::class)], $definition->getArguments());
    }

    public function testClassImplementingNeitherStopsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.auth', stdClass::class);
        $container->setAlias(TaskApiAuthenticatorInterface::class, 'app.auth');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_task_board: the service "app.auth" (stdClass) of api.hooks.authenticator must implement "' . TaskApiRequestAuthenticatorInterface::class . '" (or the deprecated "' . TaskApiAuthenticatorInterface::class . '").');

        (new TaskBoardPortsPass())->process($container);
    }

    public function testUnknownClassesAreLeftToTheRuntimeFactory(): void
    {
        $container = new ContainerBuilder();
        $container->register('app.factory_made', TaskApiAuthenticatorInterface::class)->setFactory([LegacyAuthenticator::class, 'create']);
        $container->setAlias(TaskApiAuthenticatorInterface::class, 'app.factory_made');
        $container->register('app.synthetic')->setSynthetic(true);
        $container->setAlias(TaskBoardTargetsInterface::class, 'app.synthetic');
        $container->register('app.parameter_class', '%app.class%');
        $container->setParameter('app.class', 'App\\DoesNotExist');
        $container->setAlias(TaskBoardPortsPass::PORT_PREFIX . 'first_board_provisioner', 'app.parameter_class');
        $container->setAlias(TaskBoardPortsPass::PORT_PREFIX . 'people_inviter', 'app.nowhere');

        (new TaskBoardPortsPass())->process($container);

        self::assertSame([Ports::class, 'apiAuthenticator'], $container->getDefinition(TaskApiAuthenticatorInterface::class)->getFactory());
        self::assertSame([Ports::class, 'boardTargets'], $container->getDefinition(TaskBoardTargetsInterface::class)->getFactory());
        self::assertSame([Ports::class, 'firstBoardProvisioner'], $container->getDefinition(TaskBoardPortsPass::PORT_PREFIX . 'first_board_provisioner')->getFactory());
        self::assertSame([Ports::class, 'peopleInviter'], $container->getDefinition(TaskBoardPortsPass::PORT_PREFIX . 'people_inviter')->getFactory());
    }

    public function testAbsentPortsAreLeftAlone(): void
    {
        $container = new ContainerBuilder();
        $before    = $container->getDefinitions();

        (new TaskBoardPortsPass())->process($container);

        self::assertEquals($before, $container->getDefinitions());
    }

    /**
     * Audit OM TB-I1: a host resolver implementing the twin takes the public twin alias, so autowiring never hands out
     * the bundle's laxer default instead of the configured one.
     */
    public function testTheConfiguredTwinActorResolverTakesTheTwinAlias(): void
    {
        $container = $this->actorContainer(TwinActorResolver::class);

        (new TaskBoardPortsPass())->process($container);

        self::assertTrue($container->hasAlias(SyncActorProviderInterface::class));
        self::assertSame('app.actor', (string) $container->getAlias(SyncActorProviderInterface::class));
        self::assertTrue($container->getAlias(SyncActorProviderInterface::class)->isPublic());
    }

    public function testADeprecatedOnlyActorResolverLeavesTheTwinAliasOnTheDefault(): void
    {
        $container = $this->actorContainer(LegacyActorResolver::class);

        (new TaskBoardPortsPass())->process($container);

        self::assertSame(ConnectionCreatorActorResolver::class, (string) $container->getAlias(SyncActorProviderInterface::class));
    }

    public function testNoConfiguredActorResolverLeavesTheTwinAliasOnTheDefault(): void
    {
        $container = $this->actorContainer(TwinActorResolver::class);
        $container->setParameter(TaskBoardPortsPass::ACTOR_RESOLVER_PARAMETER, '');

        (new TaskBoardPortsPass())->process($container);

        self::assertSame(ConnectionCreatorActorResolver::class, (string) $container->getAlias(SyncActorProviderInterface::class));
    }

    /** @param class-string $resolver */
    private function actorContainer(string $resolver): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('app.actor', $resolver);
        $container->register(ConnectionCreatorActorResolver::class, ConnectionCreatorActorResolver::class);
        $container->setAlias(SyncActorResolverInterface::class, 'app.actor');
        $container->setAlias(SyncActorProviderInterface::class, ConnectionCreatorActorResolver::class)->setPublic(true);
        $container->setParameter(TaskBoardPortsPass::ACTOR_RESOLVER_PARAMETER, 'app.actor');

        return $container;
    }
}

final class LegacyAuthenticator implements TaskApiAuthenticatorInterface
{
    public static function create(): self
    {
        return new self();
    }

    public function authenticate(Request $request): TaskApiAuthFailure
    {
        return TaskApiAuthFailure::Invalid;
    }
}

final class TwinAuthenticator implements TaskApiRequestAuthenticatorInterface
{
    public function authenticate(TaskApiRequestContext $request): TaskApiAuthFailure
    {
        return TaskApiAuthFailure::Invalid;
    }
}

final class TwinTargets implements TaskBoardTargetIdsInterface
{
    public function targetIds(TaskSnapshot $task, object $user): array
    {
        return [];
    }
}

final class TwinProvisioning implements FirstBoardProvisioningInterface
{
    public function provision(TaskBoardTenant $tenant, object $user): ?string
    {
        return null;
    }
}

final class TwinActorResolver implements SyncActorProviderInterface
{
    public function actorFor(TaskBoardSnapshot $board, ?string $actorId = null): ?object
    {
        return null;
    }
}

final class LegacyActorResolver implements SyncActorResolverInterface
{
    public function actorFor(TaskBoard $board, ?string $actorId = null): ?object
    {
        return null;
    }
}
