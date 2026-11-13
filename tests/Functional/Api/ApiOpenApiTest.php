<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function file_get_contents;
use function is_array;
use function is_string;
use function preg_match_all;
use function sort;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;

/**
 * Spec 213 FR-018: docs/openapi.yaml documents every route of the API, and only those, and every `$ref` in it resolves.
 */
final class ApiOpenApiTest extends ApiTestCase
{
    /** Route variables as the routes name them => as ClickUp and the document name them. */
    private const NAMES = ['{teamId}' => '{team_id}', '{spaceId}' => '{space_id}', '{listId}' => '{list_id}', '{taskId}' => '{task_id}', '{tagName}' => '{tag_name}', '{fieldId}' => '{field_id}', '{linksTo}' => '{links_to}', '{checklistId}' => '{checklist_id}', '{itemId}' => '{checklist_item_id}', '{timerId}' => '{timer_id}', '{statusId}' => '{status_id}', '{folderId}' => '{folder_id}'];

    public function testTheDocumentHasEveryRouteAndNothingElse(): void
    {
        $document = $this->document();
        $router   = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = [];
        foreach ($router->getRouteCollection()->all() as $route) {
            if ($route->getDefault(TaskApiRequestListener::ROUTE_FLAG) === true) {
                foreach ($route->getMethods() as $method) {
                    $routes[] = strtolower($method) . ' ' . str_replace(array_keys(self::NAMES), self::NAMES, substr($route->getPath(), strlen('/api/tasks')));
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

        self::assertSame($routes, $documented);
    }

    public function testEveryReferenceResolves(): void
    {
        $text     = (string) file_get_contents(__DIR__ . '/../../../docs/openapi.yaml');
        $document = $this->document();
        preg_match_all("~\\\$ref: '#/components/([a-zA-Z]+)/([a-zA-Z]+)'~", $text, $matches);
        self::assertNotEmpty($matches[0]);
        foreach ($matches[1] as $index => $section) {
            $name = $matches[2][$index];
            self::assertTrue(is_array($document['components'][$section][$name] ?? null), $section . '/' . $name);
        }
        self::assertTrue(is_string($document['openapi'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $document = Yaml::parseFile(__DIR__ . '/../../../docs/openapi.yaml');
        self::assertIsArray($document);

        return $document;
    }
}
