<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function getenv;
use function is_string;
use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host kernel of the functional and repository tests (REQ-TEST-003): the bundle with Doctrine, Security, Twig, UiKit
 * and FormKit, the bundle routes imported with `type: nowo_task_board`. The database is SQLite in memory, or the
 * DSN in TASK_BOARD_TEST_DATABASE_URL (CI runs the suite against MySQL too). `$taskBoard` is merged into the
 * `nowo_task_board` configuration.
 */
final class FunctionalKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $taskBoard
     * @param bool $realHttpClient since 1.23: keep the bundle's own HTTP clients (audit EG-L4) instead of the fixtures
     */
    public function __construct(private readonly array $taskBoard = [], private readonly bool $realHttpClient = false)
    {
        // TASK_BOARD_TEST_DEBUG=0 builds the kernel as production does (no query logging or profiling): the memory
        // measurements of the import (audit EB-L7) use it.
        parent::__construct('test', getenv('TASK_BOARD_TEST_DEBUG') !== '0');
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new SecurityBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new TaskBoardBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-task-board-functional/' . md5(serialize([$this->taskBoard, self::databaseUrl(), $this->debug, $this->realHttpClient])) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-task-board-functional/log';
    }

    public static function databaseUrl(): string
    {
        $url = getenv('TASK_BOARD_TEST_DATABASE_URL');

        return is_string($url) && $url !== '' ? $url : 'sqlite:///:memory:';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'functional-test',
            'test'                 => true,
            'http_method_override' => false,
            'csrf_protection'      => true,
            'form'                 => ['csrf_protection' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'property_info'        => ['with_constructor_extractor' => true],
            'router'               => ['utf8' => true],
            'assets'               => true,
            'translator'           => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['en']],
            'default_locale'       => 'en',
        ]);
        $container->services()->set('logger', RecordingLogger::class)->public();
        $container->services()->set('app.assignee_provider', OwnerOnlyAssigneeProvider::class)->autowire();
        $container->services()->set('security.csrf.token_manager', PredictableCsrfTokenManager::class);
        // Since 1.23: the host's avatar resolver (no picture unless a test sets one).
        $container->services()->set('app.avatars', CountingAvatarResolver::class);
        $container->services()->alias(TaskMemberAvatarResolverInterface::class, 'app.avatars');
        // Since 1.24: a tenant-aware create check and a default board view, used when a test configures them.
        $container->services()->set('app.tenant_create_checker', TenantCreateChecker::class)->public();
        $container->services()->set('app.default_view', PreferredViewResolver::class)->public();
        // Since 1.25: host-facing services no bundle code calls (the host does), kept public for the tests.
        $container->services()->alias('app.board_eraser', TaskBoardEraser::class)->public();
        $container->services()->alias('app.snapshot_purger', TaskTimeEntrySnapshotPurger::class)->public();
        $container->extension('doctrine', [
            'dbal' => ['url' => self::databaseUrl()],
            'orm'  => [
                'auto_mapping' => true,
                'mappings'     => ['FunctionalApp' => [
                    'type'      => 'attribute',
                    'is_bundle' => false,
                    'dir'       => __DIR__ . '/Entity',
                    'prefix'    => 'Nowo\\TaskBoardBundle\\Tests\\Functional\\App\\Entity',
                ]],
            ] + $this->nativeLazyObjects(),
        ]);
        $container->extension('security', [
            'providers' => ['app' => ['entity' => ['class' => AppUser::class, 'property' => 'email']]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'app']],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('nowo_task_board', ['user_class' => AppUser::class] + $this->taskBoard);
        // Since 1.30.0: the host's scopes and tenant labels (used when a test sets board.scope_provider).
        $container->services()->set('app.board_scopes', ScriptedBoardScopes::class)->public();
        // Since 2.0.0: the host's plan limits and first-board provisioning.
        $container->services()->set('app.quota_listener', RecordingQuotaListener::class)->public()
            ->tag('kernel.event_listener', ['event' => TaskBoardEvents::QUOTA_CHECK]);
        $container->services()->set('app.first_board', RecordingBoardProvisioner::class)->autowire()->public();
        if (($this->taskBoard['sync']['enabled'] ?? false) === true) {
            // Since 1.23: the host side of the provider sync, with a scriptable provider and no network.
            $services = $container->services();
            $services->set('app.sync_credentials', TestCredentialResolver::class)->public();
            $services->set('app.sync_storage', RecordingAttachmentStorage::class)->public();
            $services->set('app.sync_limits', TestSyncLimitChecker::class)->public();
            $services->set('app.memory_provider', InMemoryTaskProvider::class)->public()->tag('nowo_task_board.task_provider');
            // Since 1.25: the host's tracked-time sink (used when a test sets sync.time_entries.sink).
            $services->set('app.time_sink', RecordingTimeSink::class)->public();
            // Since 1.27.0: the host's people candidates and inviter (used when a test sets sync.people.resolver / inviter).
            $services->set('app.people_resolver', ScriptedPeopleResolver::class)->autowire()->public();
            $services->set('app.people_inviter', RecordingPeopleInviter::class)->autowire()->public();
            // Since 1.28.0: a sink that keeps ownerless time, and a resolver that flags attribution-only users.
            $services->set('app.time_sink_ownerless', RecordingOwnerlessTimeSink::class)->public();
            $services->set('app.people_resolver_attribution', AttributionPeopleResolver::class)->autowire()->public();
            if (!$this->realHttpClient) {
                $services->set('nowo_task_board.sync.http_client', FixtureHttpClient::class)->public();
            }
            $services->set('app.import_users', BoardAwareUserResolver::class)->autowire();
            $services->alias(TaskImportUserResolverInterface::class, 'app.import_users');
        }
    }

    /**
     * ORM 3 on PHP 8.4+ needs native lazy objects: DoctrineBundle 2.x defaults `enable_native_lazy_objects` to false,
     * 3.x always uses them and deprecates the option.
     *
     * @return array<string, bool>
     */
    private function nativeLazyObjects(): array
    {
        if (PHP_VERSION_ID < 80400) {
            return [];
        }

        $root = (new DoctrineConfiguration(false))->getConfigTreeBuilder()->buildTree();
        $orm  = $root instanceof ArrayNode ? ($root->getChildren()['orm'] ?? null) : null;
        $node = $orm instanceof ArrayNode ? ($orm->getChildren()['enable_native_lazy_objects'] ?? null) : null;

        return $node instanceof BaseNode && !$node->isDeprecated() ? ['enable_native_lazy_objects' => true] : [];
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_task_board');
    }
}
