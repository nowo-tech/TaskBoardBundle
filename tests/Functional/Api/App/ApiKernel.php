<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
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

use function md5;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Host kernel of the API tests: the bundle with `api.enabled` and a stateless firewall on the API's prefix. `$withFirewall`
 * false: the host forgot it, and the API must fail closed.
 */
final class ApiKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $api `nowo_task_board.api`, merged over a complete set of hooks
     * @param array<string, mixed> $taskBoard the rest of `nowo_task_board`
     */
    public function __construct(private readonly array $api = [], private readonly array $taskBoard = [], private readonly bool $withFirewall = true)
    {
        parent::__construct('test', true);
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
        return __DIR__ . '/../../App';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-task-board-api/' . md5(serialize([$this->api, $this->taskBoard, $this->withFirewall, FunctionalKernel::databaseUrl()])) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-task-board-api/log';
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
            'default_locale'       => 'en',
        ]);
        $services = $container->services();
        $services->set('logger', RecordingLogger::class)->public();
        $services->set('app.api_host', ApiTestHost::class)->autowire()->public();
        $services->set('app.api_firewall', ApiFirewallAuthenticator::class);
        $container->extension('doctrine', [
            'dbal' => ['url' => FunctionalKernel::databaseUrl()],
            'orm'  => [
                'auto_mapping' => true,
                'mappings'     => ['FunctionalApp' => [
                    'type'      => 'attribute',
                    'is_bundle' => false,
                    'dir'       => __DIR__ . '/../../App/Entity',
                    'prefix'    => 'Nowo\\TaskBoardBundle\\Tests\\Functional\\App\\Entity',
                ]],
            ] + $this->nativeLazyObjects(),
        ]);
        $container->extension('security', [
            'providers' => ['app' => ['entity' => ['class' => AppUser::class, 'property' => 'email']]],
            'firewalls' => ($this->withFirewall ? ['task_api' => ['pattern' => '^/api/', 'stateless' => true, 'provider' => 'app', 'custom_authenticators' => ['app.api_firewall']]] : [])
                + ['main' => ['lazy' => true, 'provider' => 'app']],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('nowo_task_board', ['user_class' => AppUser::class, 'api' => $this->api + ['enabled' => true, 'hooks' => [
            'authenticator' => 'app.api_host',
            'rate_limiter'  => 'app.api_host',
            'plan_gate'     => 'app.api_host',
            'workspace'     => 'app.api_host',
            'users'         => 'app.api_host',
        ]]] + $this->taskBoard);
    }

    /**
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
