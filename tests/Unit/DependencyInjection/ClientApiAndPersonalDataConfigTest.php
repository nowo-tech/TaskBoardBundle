<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use LogicException;
use Nowo\TaskBoardBundle\Bridge\UserKit\TaskBoardUserKitExportProvider;
use Nowo\TaskBoardBundle\ClientApi\Controller\TaskClientApiController;
use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientApiListener;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporter;
use Nowo\TaskBoardBundle\PersonalData\TaskBoardPersonalDataExporterInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Since 3.4.0: `client_api` (off by default, needs the Task API and a context policy) and `personal_data` (the
 * exporter always, UserKit's section when UserKit is installed and the switch is on).
 */
final class ClientApiAndPersonalDataConfigTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);
        self::assertSame(['principal_resolver' => null, 'context_policy' => null, 'reference_resolver' => null, 'link_visibility' => null, 'file_resolver' => null, 'source_link' => null, 'file_scheme' => null, 'prefix' => '/api/task-board', 'context_header' => 'X-Beacon-Context'], $config['client_api']);
        self::assertSame(['user_kit' => true], $config['personal_data']);
        self::assertFalse($config['sync']['people_forget']);
    }

    public function testBadValuesAreRefused(): void
    {
        foreach ([['prefix' => 'api/'], ['context_header' => 'X Bad'], ['file_scheme' => 'Beacon File']] as $client) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'client_api' => $client]]);
                self::fail('Expected a refusal');
            } catch (InvalidConfigurationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOffNothingIsRegistered(): void
    {
        $container = $this->load([]);
        self::assertFalse($container->hasDefinition('nowo_task_board.client_api.authenticator'));
        self::assertFalse($container->hasDefinition(TaskClientApiController::class));
        self::assertNull($container->getDefinition(TaskBoardRouteLoader::class)->getArguments()['$clientApiPrefix'] ?? null);
    }

    public function testItNeedsTheTaskApiAndAContextPolicy(): void
    {
        try {
            $this->load(['client_api' => ['principal_resolver' => 'app.resolver', 'context_policy' => 'app.contexts']]);
            self::fail('Expected a LogicException');
        } catch (LogicException $exception) {
            self::assertStringContainsString('api.enabled', $exception->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('context_policy');
        $this->load(['client_api' => ['principal_resolver' => 'app.resolver']] + $this->api());
    }

    public function testOnTheServicesAndTheRoutesAreRegistered(): void
    {
        $container = $this->load(['client_api' => ['principal_resolver' => 'app.resolver', 'context_policy' => 'app.contexts', 'prefix' => '/api/tb', 'file_scheme' => 'beacon-file']] + $this->api());
        self::assertSame('/api/tb/v1', $container->getParameter('nowo_task_board.client_api.v1_path_prefix'));
        self::assertTrue($container->hasDefinition('nowo_task_board.client_api.authenticator'));
        self::assertTrue($container->getDefinition(TaskClientApiListener::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(TaskClientApiController::class)->isPublic());
        self::assertSame('/api/tb/v1', $container->getDefinition(TaskBoardRouteLoader::class)->getArgument('$clientApiPrefix'));
    }

    public function testThePersonalDataExporterAndUserKitsSection(): void
    {
        $container = $this->load([]);
        self::assertSame(TaskBoardPersonalDataExporter::class, (string) $container->getAlias(TaskBoardPersonalDataExporterInterface::class));
        self::assertTrue($container->getAlias(TaskBoardPersonalDataExporterInterface::class)->isPublic());
        self::assertTrue($container->hasDefinition(TaskBoardUserKitExportProvider::class));
        self::assertSame([['priority' => -50]], $container->getDefinition(TaskBoardUserKitExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));

        $off = $this->load(['personal_data' => ['user_kit' => false]]);
        self::assertFalse($off->hasDefinition(TaskBoardUserKitExportProvider::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function api(): array
    {
        return ['api' => ['enabled' => true, 'hooks' => ['authenticator' => 'app.api']], 'security' => ['resource_scoping' => true]];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }
}
