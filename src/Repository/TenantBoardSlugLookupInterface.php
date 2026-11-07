<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 1.30.0 (spec 211), optional on a board repository: slugs are unique per tenant. A global answer would tell one tenant
 * that another tenant has a slug. A repository without it keeps the global lookup.
 */
interface TenantBoardSlugLookupInterface
{
    public function findBySlugInTenant(string $slug, ?string $tenantKey): ?TaskBoard;
}
