<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

/**
 * Default {@see TaskBoardTenantResolverInterface}: the slug is the tenant (key, slug and name); access is left to
 * the board access checks.
 */
final readonly class SlugTaskBoardTenantResolver implements TaskBoardTenantResolverInterface
{
    /**
     * The tenant named by the slug itself.
     */
    public function resolve(string $tenantSlug, object $user): TaskBoardTenant
    {
        return new TaskBoardTenant($tenantSlug, $tenantSlug, $tenantSlug);
    }
}
