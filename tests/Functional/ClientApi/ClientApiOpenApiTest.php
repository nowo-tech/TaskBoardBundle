<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\ClientApi;

use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientApiListener;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function file_get_contents;
use function is_array;
use function preg_match_all;
use function sort;
use function strlen;
use function strtolower;
use function substr;

/**
 * Since 3.4.0: docs/openapi/task-board-client-v1.yaml documents every client API route, and only those, and every `$ref`
 * in it resolves.
 */
final class ClientApiOpenApiTest extends ClientApiTestCase
{
    private const FILE = __DIR__ . '/../../../docs/openapi/task-board-client-v1.yaml';

    public function testTheDocumentHasEveryRouteAndNothingElse(): void
    {
        $document = Yaml::parse((string) file_get_contents(self::FILE));
        self::assertIsArray($document);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            if ($route->hasDefault(TaskClientApiListener::ROUTE_SCOPE)) {
                foreach ($route->getMethods() as $method) {
                    $routes[] = strtolower($method) . ' ' . substr($route->getPath(), strlen(self::V1));
                }
            }
        }
        $documented = [];
        self::assertIsArray($document['paths'] ?? null);
        foreach ($document['paths'] as $path => $operations) {
            self::assertIsArray($operations);
            foreach (array_keys($operations) as $method) {
                if ($method !== 'parameters') {
                    $documented[] = $method . ' ' . $path;
                }
            }
        }
        sort($routes);
        sort($documented);
        self::assertCount(19, $routes);
        self::assertSame($routes, $documented);

        $text = (string) file_get_contents(self::FILE);
        preg_match_all("~\\\$ref: '#/components/([a-zA-Z]+)/([a-zA-Z]+)'~", $text, $matches);
        self::assertNotEmpty($matches[0]);
        foreach ($matches[1] as $index => $section) {
            self::assertTrue(is_array($document['components'][$section][$matches[2][$index]] ?? null), $section . '/' . $matches[2][$index]);
        }
    }
}
