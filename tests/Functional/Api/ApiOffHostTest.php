<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;

use function array_filter;
use function array_map;
use function get_debug_type;
use function is_array;
use function sort;
use function str_contains;

/**
 * Spec 213, the opt-in rule: with `api.enabled: false` a host gets no route, no service it did not have, no listener and no
 * required schema, and the API's hooks may be set without any of that changing.
 */
final class ApiOffHostTest extends FunctionalTestCase
{
    private const API_NAMESPACE = 'Nowo\\TaskBoardBundle\\Api\\';

    public function testNoRouteExists(): void
    {
        $routes = $this->router()->getRouteCollection()->all();

        foreach ($routes as $name => $route) {
            self::assertFalse(str_contains($name, '_api_'), $name);
            self::assertFalse($route->hasDefault(TaskApiRequestListener::ROUTE_FLAG), $name);
            self::assertFalse(str_contains($route->getPath(), '/v2/'), $route->getPath());
        }
    }

    public function testThePathAnswersWhatAnUnknownPathAnswers(): void
    {
        $this->client->request('GET', '/api/tasks/v2/user', [], [], ['HTTP_AUTHORIZATION' => 'bt_placeholder']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertFalse($this->client->getResponse()->headers->has('X-RateLimit-Limit'));
    }

    public function testNoServiceOfTheApiExists(): void
    {
        $container = self::getContainer();
        foreach ($this->serviceIds($container) as $id) {
            self::assertFalse(str_contains($id, self::API_NAMESPACE), $id);
            self::assertFalse(str_contains($id, 'nowo_task_board.api'), $id);
        }
        self::assertFalse($container->has(TaskApiGuard::class));
        self::assertFalse($container->has(TaskApiRequestListener::class));
    }

    public function testNoParameterOfTheApiExists(): void
    {
        self::assertFalse(self::getContainer()->hasParameter('nowo_task_board.api.prefix'));
    }

    public function testNoListenerOfTheApiIsSubscribed(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ($dispatcher->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $class = is_array($listener) ? get_debug_type($listener[0]) : get_debug_type($listener);
                self::assertFalse(str_contains($class, self::API_NAMESPACE), $class . ' on ' . $event);
            }
        }
    }

    public function testNoTableOfTheApiIsRequired(): void
    {
        /** @var list<ClassMetadata<object>> $metadata */
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ($metadata as $class) {
            self::assertFalse(str_contains($class->getName(), self::API_NAMESPACE), $class->getName());
            self::assertFalse(str_contains($class->getTableName(), 'api'), $class->getTableName());
        }
        self::assertSame([], array_filter((new SchemaTool($this->em))->getUpdateSchemaSql($metadata), static fn (string $sql): bool => str_contains($sql, 'api')));
    }

    /**
     * @return list<string>
     */
    private function serviceIds(ContainerInterface $container): array
    {
        self::assertTrue(method_exists($container, 'getServiceIds'));
        $ids = array_map('strval', $container->getServiceIds());
        sort($ids);

        return $ids;
    }

    private function router(): RouterInterface
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
