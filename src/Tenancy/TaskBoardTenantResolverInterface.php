<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

/**
 * Maps the `{tenantSlug}` of a tenant URL to the host tenant (`tenancy.resolver`).
 */
interface TaskBoardTenantResolverInterface
{
    /**
     * The tenant of `$tenantSlug` for `$user`, or null when the slug names no tenant the user may open (404).
     */
    public function resolve(string $tenantSlug, object $user): ?TaskBoardTenant;
}
