<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

use function is_string;

/**
 * `tenancy.tenant_paths`: a link to a manage route (`nowo_task_board_*`) goes to the tenant copy of the route for
 * its `tenantSlug` — given as a parameter, or taken from the current request when that request is a tenant page of
 * the bundle — so templates keep generating `nowo_task_board_*` whatever the tenant. Everything else is the
 * router's. Registered only when `tenancy.enabled` is true.
 */
final readonly class TenantPathUrlGenerator implements RouterInterface, RequestMatcherInterface, WarmableInterface
{
    /**
     * Decorates the router with the tenant path routing.
     *
     * @param RequestMatcherInterface&RouterInterface&WarmableInterface $inner The decorated `router` service
     * @param TenantPaths $tenantPaths Tenant path entries matched by slug prefix
     * @param RequestStack $requestStack The current request (its `tenantSlug` on a tenant page)
     */
    public function __construct(
        private RouterInterface&RequestMatcherInterface&WarmableInterface $inner,
        private TenantPaths $tenantPaths,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        if ($this->tenantPaths->isBundleRoute($name)) {
            $slug = $parameters['tenantSlug'] ?? $this->currentTenantSlug();
            if (is_string($slug) && $slug !== '') {
                $tenantRoute = $this->tenantPaths->tenantRoute($name, $slug);
                if ($tenantRoute !== null) {
                    $name                     = $tenantRoute;
                    $parameters['tenantSlug'] = $slug;
                }
            }
        }

        return $this->inner->generate($name, $parameters, $referenceType);
    }

    /**
     * Sets the request context on the decorated router.
     */
    public function setContext(RequestContext $context): void
    {
        // @igor-ignore - The router contract: Symfony sets the request context on the router each request.
        $this->inner->setContext($context);
    }

    /**
     * Returns the request context of the decorated router.
     */
    public function getContext(): RequestContext
    {
        return $this->inner->getContext();
    }

    /**
     * Returns the route collection of the decorated router (tenant path copies included).
     */
    public function getRouteCollection(): RouteCollection
    {
        return $this->inner->getRouteCollection();
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        return $this->inner->match($pathinfo);
    }

    /**
     * @return array<string, mixed>
     */
    public function matchRequest(Request $request): array
    {
        return $this->inner->matchRequest($request);
    }

    /**
     * Warms up the decorated router cache.
     *
     * @return array<string> Classes to preload, as returned by the decorated router
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner->warmUp($cacheDir, $buildDir);
    }

    /**
     * The `tenantSlug` of the current request when it is a tenant page of the bundle; null otherwise (a host page
     * with a `tenantSlug` of its own does not leak into the task board links).
     */
    private function currentTenantSlug(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $route = $request->attributes->get('_route');
        $slug  = $request->attributes->get('tenantSlug');

        return is_string($route) && $this->tenantPaths->isTenantRoute($route) && is_string($slug) ? $slug : null;
    }
}
