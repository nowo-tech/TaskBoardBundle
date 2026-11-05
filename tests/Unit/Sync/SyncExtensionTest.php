<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use LogicException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Sync\File\OutboundUrlGuardAdapter;
use Nowo\TaskBoardBundle\Sync\File\UrlGuardInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\Hook\InlineSyncRunner;
use Nowo\TaskBoardBundle\Sync\Hook\NullTaskAttachmentStorage;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncRunnerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Routing\RouteCollection;

/**
 * Since 1.23: `sync.*` is opt-in — nothing without it; with it, the services, aliases, routes and mapping, and a
 * clear error without a credential resolver.
 */
final class SyncExtensionTest extends TestCase
{
    public function testSyncIsOffByDefault(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter('nowo_task_board.sync.enabled'));
        self::assertFalse($container->hasDefinition(ClickUpTaskProvider::class));
        self::assertFalse($container->hasAlias(ProviderCredentialResolverInterface::class));
        self::assertArrayNotHasKey('$connectionBadges', $container->getDefinition(TaskBoardTwigExtension::class)->getArguments());
        $routes = $this->routes([]);
        self::assertNull($routes->get('nowo_task_board_board_connection'));
        self::assertNotNull($routes->get('nowo_task_board_task_restore'), 'restoring an archived task is always available');
    }

    /**
     * Since 1.24: sync.actor_checker gives the default actor resolver the host's user checker; a host actor_resolver
     * still replaces the default.
     */
    public function testTheActorCheckerIsWiredAndAHostActorResolverStillWins(): void
    {
        $container = $this->load(['enabled' => true, 'credential_resolver' => 'app.credentials', 'actor_checker' => 'security.user_checker.main']);
        $checker   = $container->getDefinition(ConnectionCreatorActorResolver::class)->getArgument('$userChecker');
        self::assertInstanceOf(Reference::class, $checker);
        self::assertSame('security.user_checker.main', (string) $checker);

        $container = $this->load(['enabled' => true, 'credential_resolver' => 'app.credentials', 'actor_resolver' => 'app.active_actor']);
        self::assertSame('app.active_actor', (string) $container->getAlias(SyncActorResolverInterface::class));
    }

    public function testEnablingSyncNeedsACredentialResolver(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sync.credential_resolver');
        $this->load(['enabled' => true]);
    }

    public function testEnabledSyncWiresTheHooksTheProviderAndTheRoutes(): void
    {
        $container = $this->load(['enabled' => true, 'credential_resolver' => 'app.credentials', 'runner' => 'app.runner', 'http' => ['client' => 'app.proxy_client'], 'attachments' => ['enabled' => false]]);

        self::assertSame('app.credentials', (string) $container->getAlias(ProviderCredentialResolverInterface::class));
        self::assertSame('app.runner', (string) $container->getAlias(SyncRunnerInterface::class));
        self::assertSame(ConnectionCreatorActorResolver::class, (string) $container->getAlias(SyncActorResolverInterface::class));
        // Since 1.24: no sync.actor_checker, no user checker (only isEnabled()).
        self::assertNull($container->getDefinition(ConnectionCreatorActorResolver::class)->getArgument('$userChecker'));
        self::assertSame(NullTaskAttachmentStorage::class, (string) $container->getAlias(TaskAttachmentStorageInterface::class));
        self::assertSame('app.proxy_client', (string) $container->getAlias('nowo_task_board.sync.api_client'));
        self::assertFalse($container->getParameter('nowo_task_board.sync.storage_enabled'));
        self::assertTrue($container->hasDefinition(ClickUpTaskProvider::class));
        self::assertTrue($container->getDefinition(ClickUpTaskProvider::class)->hasTag('nowo_task_board.task_provider'));
        // The bundle's own client: never the profiler-traced framework `http_client`.
        $base = $container->getDefinition('nowo_task_board.sync.base_http_client');
        self::assertSame([HttpClient::class, 'create'], $base->getFactory());
        $badges = $container->getDefinition(TaskBoardTwigExtension::class)->getArgument('$connectionBadges');
        self::assertInstanceOf(Reference::class, $badges);
        self::assertTrue($container->getDefinition(TaskBoardRouteLoader::class)->getArgument('$syncEnabled'));
        if (class_exists(OutboundUrlGuard::class)) {
            self::assertSame(OutboundUrlGuardAdapter::class, (string) $container->getAlias(UrlGuardInterface::class));
        }

        $defaults = $this->load(['enabled' => true, 'credential_resolver' => 'app.credentials', 'attachment_storage' => 'app.storage', 'providers' => ['clickup' => ['enabled' => false]]]);
        self::assertSame(InlineSyncRunner::class, (string) $defaults->getAlias(SyncRunnerInterface::class));
        self::assertSame('app.storage', (string) $defaults->getAlias(TaskAttachmentStorageInterface::class));
        self::assertSame('nowo_task_board.sync.http_client', (string) $defaults->getAlias('nowo_task_board.sync.api_client'));
        self::assertTrue($defaults->getParameter('nowo_task_board.sync.storage_enabled'));
        self::assertFalse($defaults->hasDefinition(ClickUpTaskProvider::class));

        $routes = $this->routes(['enabled' => true]);
        foreach (['board_connection', 'board_connection_containers', 'board_connection_link', 'board_connection_refresh', 'board_connection_progress', 'board_connection_cancel', 'board_connection_unlink'] as $key) {
            self::assertNotNull($routes->get('nowo_task_board_' . $key), $key);
        }
        self::assertSame(['GET'], $routes->get('nowo_task_board_board_connection_progress')?->getMethods());
        self::assertSame(['POST'], $routes->get('nowo_task_board_board_connection_containers')?->getMethods());
    }

    public function testTheSyncMappingIsPrependedOnlyWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $container->registerExtension(new TaskBoardExtension());
        $container->loadFromExtension('nowo_task_board', ['sync' => ['enabled' => true]]);
        (new TaskBoardExtension())->prepend($container);
        $mappings = array_merge(...array_map(static fn (array $c): array => $c['orm']['mappings'] ?? [], $container->getExtensionConfig('doctrine')));
        self::assertArrayHasKey('TaskBoardSync', $mappings);

        $off = new ContainerBuilder();
        $off->registerExtension(new DoctrineExtension());
        $off->registerExtension(new TaskBoardExtension());
        $off->loadFromExtension('nowo_task_board', ['sync' => ['enabled' => false]]);
        (new TaskBoardExtension())->prepend($off);
        $mappings = array_merge(...array_map(static fn (array $c): array => $c['orm']['mappings'] ?? [], $off->getExtensionConfig('doctrine')));
        self::assertArrayNotHasKey('TaskBoardSync', $mappings);
    }

    /**
     * @param array<string, mixed> $sync
     */
    private function load(array $sync): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'sync' => $sync]], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $sync
     */
    private function routes(array $sync): RouteCollection
    {
        $container = $this->load($sync === [] ? [] : $sync + ['credential_resolver' => 'app.credentials']);
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes = $container->getParameter('nowo_task_board.routes');

        return (new TaskBoardRouteLoader($routes, '', [], ($sync['enabled'] ?? false) === true))->load('.', 'nowo_task_board');
    }
}
