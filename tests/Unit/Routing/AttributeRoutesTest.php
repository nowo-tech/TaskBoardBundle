<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Routing;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Routing\ControllerAttributeRouteLoader;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function count;
use function is_string;
use function is_subclass_of;
use function str_contains;
use function str_starts_with;

/**
 * REQ-SF-004 (2.21.1): the `#[Route]` attributes on the controllers hold each route's default name and path (the
 * `routes.*` defaults of the configuration, `multi_board.path`, the API path after `api.prefix`), every attribute route
 * is used once by the `type: nowo_task_board` loader, and no controller is autoconfigured (rule 7).
 */
final class AttributeRoutesTest extends TestCase
{
    public function testTheAttributesHoldTheDefaultNamesAndPathsAndEveryOneIsUsed(): void
    {
        /** @var array{routes: array<string, array{path: string, name: string}>, multi_board: array{path: string}} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class]]);
        $loader = new TaskBoardRouteLoader(
            routes: $config['routes'],
            routePrefix: '',
            syncEnabled: true,
            taskNumbersEnabled: true,
            calendarEnabled: true,
            apiPrefix: '/api-prefix',
            moveEnabled: true,
            recurrenceEnabled: true,
            multiBoardPath: $config['multi_board']['path'],
            templatesEnabled: true,
            questionsEnabled: true,
            importPlansEnabled: true,
            customFieldsEnabled: true,
        );

        $declared = [];
        $reader   = new ControllerAttributeRouteLoader();
        foreach (TaskBoardRouteLoader::CONTROLLERS as $controller) {
            foreach ($reader->load($controller)->all() as $name => $route) {
                $declared[(string) $route->getDefault('_controller')] = [$name, $route->getPath()];
            }
        }

        $used = [];
        foreach ($loader->load('.', 'nowo_task_board')->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            self::assertIsString($controller);
            self::assertArrayHasKey($controller, $declared);
            $path = str_starts_with($name, 'nowo_task_board_api_') ? '/api-prefix' . $declared[$controller][1] : $declared[$controller][1];
            self::assertSame([$declared[$controller][0], $path], [$name, $route->getPath()], $controller);
            $used[$controller] = true;
        }

        self::assertCount(count($declared), $used, 'every #[Route] attribute is a route of the loader');
    }

    public function testNoControllerIsAutoconfigured(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([[
            'user_class'     => User::class,
            'api'            => ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth']],
            'sync'           => ['enabled' => true, 'credential_resolver' => 'app.credentials'],
            'move'           => ['enabled' => true],
            'recurrence'     => ['enabled' => true],
            'multi_board'    => ['enabled' => true],
            'task_templates' => ['enabled' => true],
            'task_questions' => ['enabled' => true],
        ]], $container);

        $controllers = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (!is_string($class) || !str_starts_with($class, 'Nowo\\TaskBoardBundle\\') || !str_contains($class, '\\Controller\\')) {
                continue;
            }
            $controllers[] = $class;
            self::assertFalse($definition->isAutoconfigured(), $class . ' must not be autoconfigured');
            self::assertTrue($definition->hasTag('controller.service_arguments'), $class);
            self::assertSame(is_subclass_of($class, AbstractController::class), $definition->hasTag('container.service_subscriber'), $class);
        }

        foreach (TaskBoardRouteLoader::CONTROLLERS as $controller) {
            self::assertContains($controller, $controllers);
        }
    }
}
