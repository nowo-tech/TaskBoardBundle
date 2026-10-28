<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use function array_column;
use function in_array;
use function is_string;
use function preg_quote;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * `tenancy.tenant_paths`: which copy of the manage routes a tenant slug uses, and the bundle route a copied route
 * stands for (so a host's listeners can test `nowo_task_board_board` whatever the copy).
 */
final readonly class TenantPaths
{
    /** Default route requirement of `{tenantSlug}` after the slug prefix. */
    public const DEFAULT_SLUG_PATTERN = '[A-Za-z0-9_-]+';

    /** @var list<string> */
    private array $routeNames;

    /**
     * @param list<array{path: string, name_prefix: string, slug_prefix?: string, slug_requirement?: string|null}> $tenantPaths `tenancy.tenant_paths`
     * @param array<string, array{path?: string, name?: string}> $routes `routes` (logical key => path / name)
     * @param bool $enabled `tenancy.enabled`: without it no copy exists
     */
    public function __construct(
        private array $tenantPaths = [],
        array $routes = [],
        private bool $enabled = false,
    ) {
        $names = [];
        foreach (array_column($routes, 'name') as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        $this->routeNames = $names;
    }

    /**
     * Whether the manage routes have tenant copies.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->tenantPaths !== [];
    }

    /**
     * The configured tenant paths.
     *
     * @return list<array{path: string, name_prefix: string, slug_prefix?: string, slug_requirement?: string|null}>
     */
    public function all(): array
    {
        return $this->isEnabled() ? $this->tenantPaths : [];
    }

    /**
     * Whether `$route` is one of the bundle's manage route names (as configured under `routes.*.name`).
     */
    public function isBundleRoute(string $route): bool
    {
        return in_array($route, $this->routeNames, true);
    }

    /**
     * The route name prefix of the first tenant path whose slug prefix `$tenantSlug` starts with (null: none).
     */
    public function namePrefixFor(string $tenantSlug): ?string
    {
        foreach ($this->all() as $tenantPath) {
            if (str_starts_with($tenantSlug, $tenantPath['slug_prefix'] ?? '')) {
                return $tenantPath['name_prefix'];
            }
        }

        return null;
    }

    /**
     * The tenant copy of a bundle route for `$tenantSlug` (`<name_prefix><route>`), or null when the route is not a
     * bundle route or no tenant path serves the slug.
     */
    public function tenantRoute(string $route, string $tenantSlug): ?string
    {
        if (!$this->isBundleRoute($route)) {
            return null;
        }

        $prefix = $this->namePrefixFor($tenantSlug);

        return $prefix === null ? null : $prefix . $route;
    }

    /**
     * The bundle route a route name stands for: `<name_prefix>nowo_task_board_x` → `nowo_task_board_x`; others
     * unchanged.
     */
    public function bundleRoute(string $route): string
    {
        foreach ($this->all() as $tenantPath) {
            $prefix = $tenantPath['name_prefix'];
            if (str_starts_with($route, $prefix) && $this->isBundleRoute(substr($route, strlen($prefix)))) {
                return substr($route, strlen($prefix));
            }
        }

        return $route;
    }

    /**
     * Whether `$route` is a tenant copy of a bundle route.
     */
    public function isTenantRoute(string $route): bool
    {
        return $this->bundleRoute($route) !== $route;
    }

    /**
     * Route requirement of `{tenantSlug}` for a tenant path entry.
     *
     * @param array{slug_prefix?: string, slug_requirement?: string|null} $tenantPath
     */
    public static function slugRequirement(array $tenantPath): string
    {
        $requirement = $tenantPath['slug_requirement'] ?? null;
        if (is_string($requirement) && $requirement !== '') {
            return $requirement;
        }

        return preg_quote($tenantPath['slug_prefix'] ?? '', '#') . self::DEFAULT_SLUG_PATTERN;
    }
}
