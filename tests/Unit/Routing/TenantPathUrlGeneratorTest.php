<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Routing;

use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Routing\TenantPaths;
use Nowo\TaskBoardBundle\Routing\TenantPathUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class TenantPathUrlGeneratorTest extends TestCase
{
    private const ROUTES = [
        'index' => ['path' => '/tools/task-board', 'name' => 'nowo_task_board_index'],
        'board' => ['path' => '/tools/task-board/board/{boardId}', 'name' => 'nowo_task_board_board'],
    ];

    private const TENANCY = [
        'enabled'      => true,
        'tenant_paths' => [['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_', 'slug_prefix' => '', 'slug_requirement' => '\d+']],
    ];

    public function testExplicitSlugGoesToTheTenantCopy(): void
    {
        $generator = $this->generator(new RequestStack());

        self::assertSame('/projects/42/tasks/board/b1', $generator->generate('nowo_task_board_board', ['boardId' => 'b1', 'tenantSlug' => '42']));
        self::assertSame('/tools/task-board/board/b1', $generator->generate('nowo_task_board_board', ['boardId' => 'b1']));
        self::assertSame('/home?tenantSlug=42', $generator->generate('app_home', ['tenantSlug' => '42']));
    }

    public function testSlugOfTheCurrentTenantPageIsReused(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_project_nowo_task_board_index');
        $request->attributes->set('tenantSlug', '7');
        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame('/projects/7/tasks/board/b1', $this->generator($stack)->generate('nowo_task_board_board', ['boardId' => 'b1']));
    }

    public function testSlugOfAHostPageDoesNotLeak(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'app_uptime');
        $request->attributes->set('tenantSlug', '7');
        $stack = new RequestStack();
        $stack->push($request);

        self::assertSame('/tools/task-board/board/b1', $this->generator($stack)->generate('nowo_task_board_board', ['boardId' => 'b1']));
    }

    public function testSlugNoPathServesKeepsTheBundleRoute(): void
    {
        $tenancy                                   = self::TENANCY;
        $tenancy['tenant_paths'][0]['slug_prefix'] = 'p-';
        $generator                                 = $this->generator(new RequestStack(), $tenancy);

        self::assertSame('/tools/task-board/board/b1?tenantSlug=42', $generator->generate('nowo_task_board_board', ['boardId' => 'b1', 'tenantSlug' => '42']));
    }

    public function testDelegatesTheRouterContract(): void
    {
        $inner = $this->createMock(TestRouter::class);
        $inner->expects(self::once())->method('setContext');
        $inner->method('getContext')->willReturn($context = new RequestContext());
        $inner->method('getRouteCollection')->willReturn($collection = new RouteCollection());
        $inner->method('match')->willReturn(['_route' => 'a']);
        $inner->method('matchRequest')->willReturn(['_route' => 'b']);
        $inner->method('warmUp')->willReturn(['C']);

        $generator = new TenantPathUrlGenerator($inner, new TenantPaths(), new RequestStack());
        $generator->setContext(new RequestContext());

        self::assertSame($context, $generator->getContext());
        self::assertSame($collection, $generator->getRouteCollection());
        self::assertSame(['_route' => 'a'], $generator->match('/a'));
        self::assertSame(['_route' => 'b'], $generator->matchRequest(new Request()));
        self::assertSame(['C'], $generator->warmUp('/tmp'));
    }

    /**
     * @param array<string, mixed> $tenancy
     */
    private function generator(RequestStack $stack, array $tenancy = self::TENANCY): TenantPathUrlGenerator
    {
        $collection = (new TaskBoardRouteLoader(self::ROUTES, '', $tenancy))->load('.');
        $collection->add('app_home', new Route('/home'));
        $context = new RequestContext();
        $urls    = new UrlGenerator($collection, $context);
        $matcher = new UrlMatcher($collection, $context);

        $inner = $this->createMock(TestRouter::class);
        $inner->method('generate')->willReturnCallback($urls->generate(...));
        $inner->method('match')->willReturnCallback($matcher->match(...));

        // @phpstan-ignore argument.type
        return new TenantPathUrlGenerator($inner, new TenantPaths($tenancy['tenant_paths'], self::ROUTES, true), $stack);
    }
}

/**
 * The router contract the decorator expects (the framework router implements all three).
 */
interface TestRouter extends RouterInterface, RequestMatcherInterface, WarmableInterface
{
}
