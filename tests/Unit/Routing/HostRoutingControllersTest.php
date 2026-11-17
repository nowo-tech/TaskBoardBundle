<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Routing;

use Nowo\TaskBoardBundle\Tests\Stub\HostRoutingKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function in_array;
use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;

/**
 * REQ-SF-004 rule 7: the bundle routes are opt-in. A host whose routing only holds the Symfony default
 * `routing.controllers` resource must not get any bundle route; they exist only where the host imports the
 * `type: nowo_task_board` route loader. Since 2.22.0 (audit SF4-I2) also with every feature turned on (API, sync,
 * calendar, task numbers, move, recurrence, multi-board, templates), whose controllers are registered then.
 */
final class HostRoutingControllersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo_task_board_routing_kernel_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function features(): iterable
    {
        yield 'default configuration' => [false];
        yield 'every feature on' => [true];
    }

    #[DataProvider('features')]
    public function testRoutingControllersResourceDoesNotLoadTheBundleRoutes(bool $allFeatures): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $bundleRoutes = [];
        foreach ($this->routes(false, $allFeatures)->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (str_starts_with($name, 'nowo_task_board') || (is_string($controller) && str_starts_with($controller, 'Nowo\\TaskBoardBundle\\'))) {
                $bundleRoutes[] = $name;
            }
        }

        self::assertSame([], $bundleRoutes, 'The bundle routes must only exist when the host imports them.');
    }

    #[DataProvider('features')]
    public function testImportedRouteLoaderRegistersTheBundleRoutes(bool $allFeatures): void
    {
        $names = [];
        foreach ($this->routes(true, $allFeatures)->all() as $name => $route) {
            if (str_starts_with($name, 'nowo_task_board')) {
                $names[] = $name;
            }
        }

        self::assertNotSame([], $names);
        self::assertContains('nowo_task_board_task_menu', $names);
        $featureRoutes = ['nowo_task_board_api_task', 'nowo_task_board_board_connection', 'nowo_task_board_calendar', 'nowo_task_board_task_by_number', 'nowo_task_board_task_duplicate', 'nowo_task_board_task_recurrence', 'nowo_task_board_tasks', 'nowo_task_board_api_task_templates'];
        foreach ($featureRoutes as $route) {
            self::assertSame($allFeatures, in_array($route, $names, true), $route);
        }
    }

    private function routes(bool $importBundleRoutes, bool $allFeatures = false): RouteCollection
    {
        $kernel = new HostRoutingKernel($this->dir . ($importBundleRoutes ? '/imported' : '/controllers-only') . ($allFeatures ? '-all' : ''), $importBundleRoutes, $allFeatures);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            return $router->getRouteCollection();
        } finally {
            $kernel->shutdown();
        }
    }
}
