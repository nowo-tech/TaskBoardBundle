<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

use Nowo\TaskBoardBundle\Support\ExactKey;

/**
 * Default {@see TaskBoardTenantResolverInterface}: the slug is the tenant (key, slug and name); access is left to
 * the board access checks.
 */
final readonly class SlugTaskBoardTenantResolver implements TaskBoardTenantResolverInterface
{
    /**
     * The tenant named by the slug itself.
     */
    public function resolve(string $tenantSlug, object $user): ?TaskBoardTenant
    {
        // Since 2.15.1 (REQ-SEC-006): a slug that cannot be a tenant key exactly names no tenant (404).
        return ExactKey::isUsable($tenantSlug) ? new TaskBoardTenant($tenantSlug, $tenantSlug, $tenantSlug) : null;
    }
}
