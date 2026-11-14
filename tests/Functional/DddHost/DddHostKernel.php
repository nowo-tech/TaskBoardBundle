<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Service\TaskTimeEntrySnapshotPurger;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingLogger;
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
use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Since 2.15.0 (REQ-ARCH-001, the DDD / hexagonal host path): a host whose users are plain PHP objects (no Doctrine
 * entity, an in-memory security provider), with every opt-in of the DDD path at once — the XML mapping
 * (`database.mapping_type: xml`), the private entity manager, user references by id (`database.user_reference: id` and
 * `user_directory`), and host ports implemented through their framework-free twins only. Doctrine maps the TaskBoard
 * entities and nothing else (no auto_mapping, no attribute mapping).
 */
final class DddHostKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $taskBoard merged into the `nowo_task_board` configuration
     */
    public function __construct(private readonly array $taskBoard = [])
    {
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
        return __DIR__ . '/../App';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-task-board-ddd/' . md5(serialize([$this->taskBoard, FunctionalKernel::databaseUrl(), $this->debug])) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-task-board-ddd/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'               => 'ddd-test',
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
        $services = $container->services();
        $services->set('logger', RecordingLogger::class)->public();
        $services->set('security.csrf.token_manager', PredictableCsrfTokenManager::class);
        $services->set('app.users', DddUsers::class)->public();
        $services->set('app.cause_text', DddCauseText::class);
        $services->set('app.multi_scope', DddMultiBoardScope::class);
        // Since 2.19.0 (Beacon spec 234): used when a test sets member_badge_provider / write_provenance_provider.
        $services->set('app.ddd_provenance', DddProvenance::class);
        $container->extension('doctrine', [
            'dbal' => ['url' => FunctionalKernel::databaseUrl()],
            'orm'  => ['auto_mapping' => false] + $this->nativeLazyObjects(),
        ]);
        $container->extension('security', [
            'providers' => ['app' => ['id' => 'app.users']],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'app']],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('nowo_task_board', [
            'user_class'        => DddUser::class,
            'user_directory'    => 'app.users',
            'assignee_provider' => 'app.users',
            'history'           => ['cause_labeler' => 'app.cause_text'],
            'comments'          => ['cause_labeler' => 'app.cause_text'],
            'database'          => ['mapping_type' => 'xml', 'private_entity_manager' => true, 'user_reference' => 'id'],
            'multi_board'       => ['enabled' => true, 'scope_provider' => 'app.multi_scope'],
        ] + $this->taskBoard);
        $services->alias('app.task_board_em', TaskBoardEntityManager::class)->public();
        $services->alias('app.snapshot_purger', TaskTimeEntrySnapshotPurger::class)->public();
    }

    /**
     * ORM 3 on PHP 8.4+ needs native lazy objects: DoctrineBundle 2.x defaults `enable_native_lazy_objects` to false,
     * 3.x always uses them and deprecates the option (as the functional kernel does).
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
