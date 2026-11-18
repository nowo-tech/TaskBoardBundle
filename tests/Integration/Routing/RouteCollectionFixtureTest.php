<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Integration\Routing;

use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiKernel;
use Nowo\TaskBoardBundle\Tests\Functional\App\FunctionalKernel;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function str_starts_with;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * REQ-SF-004 (attribute routes, 2.21.1): the route collection hosts get through the `type: nowo_task_board` import is
 * pinned by `tests/Fixtures/Routing/route-collections.json`, a `debug:router`-like dump of the 2.21.0 route loader (routes
 * built in PHP) taken before the routes moved to `#[Route]` attributes. Every route keeps its name, order, path, methods,
 * requirements, defaults, options, host, schemes and condition: with the default configuration, every optional feature,
 * a route prefix with renamed / moved routes and tenant copies, and the Task API (default and custom prefix).
 * `TASK_BOARD_ROUTE_FIXTURE_WRITE=1` rewrites the fixture (only to record an intended change: 2.22.0 added `task_menu`).
 */
final class RouteCollectionFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Routing/route-collections.json';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function scenarios(): iterable
    {
        foreach (self::kernels() as $name => $kernel) {
            yield $name => [$name];
        }
    }

    #[DataProvider('scenarios')]
    public function testRouteCollectionMatchesTheFixture(string $scenario): void
    {
        $actual = self::export($this->routes($scenario));

        if (getenv('TASK_BOARD_ROUTE_FIXTURE_WRITE') === '1') {
            $all            = self::fixture();
            $all[$scenario] = $actual;
            ksort($all);
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }

        $expected = self::fixture()[$scenario] ?? null;
        self::assertIsArray($expected, 'No fixture for scenario ' . $scenario);
        self::assertSame($expected, $actual);
    }

    /**
     * The host kernel of each scenario: [kernel class, its constructor arguments].
     *
     * @return array<string, array{0: class-string<KernelInterface>, 1: list<mixed>}>
     */
    private static function kernels(): array
    {
        $tenancy = ['enabled' => true, 'tenant_paths' => [
            ['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_', 'slug_prefix' => 'p-'],
            ['path' => '/teams/{tenantSlug}/', 'name_prefix' => 'app_team_', 'slug_requirement' => '[a-z]+'],
        ]];

        return [
            // The manage routes only.
            'default' => [FunctionalKernel::class, [[]]],
            // Every optional route: sync connections, numbers, calendar, move / duplicate, recurrence, multi-board.
            'all_features' => [FunctionalKernel::class, [SyncFunctionalTestCase::SYNC_CONFIG + [
                'task_numbers'   => ['enabled' => true],
                'calendar'       => ['enabled' => true],
                'move'           => ['enabled' => true],
                'recurrence'     => ['enabled' => true],
                'multi_board'    => ['enabled' => true],
                'task_templates' => ['enabled' => true],
            ]]],
            // A route prefix, renamed and moved routes (one outside the base path), tenant copies and a multi-board path
            // with a variable (never copied).
            'custom_prefix_tenancy' => [FunctionalKernel::class, [[
                'route_prefix' => '/acme',
                'routes'       => [
                    'index'          => ['path' => '/work', 'name' => 'acme_work'],
                    'board'          => ['path' => '/work/b/{boardId}', 'name' => 'acme_board'],
                    'task'           => ['path' => '/items/{taskId}', 'name' => 'acme_item'],
                    'task_by_number' => ['path' => '/work/n/{number}', 'name' => 'acme_number'],
                ],
                'tenancy'      => $tenancy,
                'task_numbers' => ['enabled' => true],
                'move'         => ['enabled' => true],
                'recurrence'   => ['enabled' => true],
                'multi_board'  => ['enabled' => true, 'path' => '/orgs/{organisation}/tasks/'],
            ]]],
            // A multi-board list at the root.
            'multi_board_root' => [FunctionalKernel::class, [['multi_board' => ['enabled' => true, 'path' => '/']]]],
            // The Task API at its default prefix.
            'api_default' => [ApiKernel::class, [[], []]],
            // The Task API at another prefix, with templates: neither route_prefix nor the tenant copies apply to it.
            'api_custom' => [ApiKernel::class, [['prefix' => '/rest/tasks'], [
                'route_prefix'   => '/acme',
                'tenancy'        => $tenancy,
                'task_templates' => ['enabled' => true],
            ]]],
            // Since 2.23.0 (Beacon spec 242): the question routes of the pages (with their tenant copies) and of the API.
            'questions' => [ApiKernel::class, [[], [
                'tenancy'        => $tenancy,
                'task_questions' => ['enabled' => true],
            ]]],
        ];
    }

    private function routes(string $scenario): RouteCollection
    {
        [$class, $arguments] = self::kernels()[$scenario];
        $kernel              = new $class(...$arguments);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            return $router->getRouteCollection();
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * The bundle routes (by `_controller`) in collection order, with every attribute of each route, and the aliases.
     *
     * @return array{routes: list<array<string, mixed>>, aliases: array<string, string>}
     */
    private static function export(RouteCollection $collection): array
    {
        $routes = [];
        foreach ($collection->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !str_starts_with($controller, 'Nowo\\TaskBoardBundle\\')) {
                continue;
            }
            $routes[] = [
                'name'         => $name,
                'path'         => $route->getPath(),
                'host'         => $route->getHost(),
                'schemes'      => $route->getSchemes(),
                'methods'      => $route->getMethods(),
                'defaults'     => $route->getDefaults(),
                'requirements' => $route->getRequirements(),
                'options'      => $route->getOptions(),
                'condition'    => $route->getCondition(),
            ];
        }

        $aliases = [];
        foreach ($collection->getAliases() as $name => $alias) {
            if (str_starts_with($name, 'Nowo\\TaskBoardBundle\\') || str_starts_with($alias->getId(), 'nowo_task_board')) {
                $aliases[$name] = $alias->getId();
            }
        }

        return ['routes' => $routes, 'aliases' => $aliases];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $json = @file_get_contents(self::FIXTURE);
        if (!is_string($json)) {
            return [];
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
