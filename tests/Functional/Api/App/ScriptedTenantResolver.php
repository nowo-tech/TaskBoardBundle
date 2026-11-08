<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api\App;

use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;

use function in_array;

/**
 * A host's tenant resolver: it opens only the tenants a test lists, whatever the API's workspace hook says.
 */
final class ScriptedTenantResolver implements TaskBoardTenantResolverInterface
{
    /** @var list<string> */
    public static array $open = [];

    public function resolve(string $tenantSlug, object $user): ?TaskBoardTenant
    {
        return in_array($tenantSlug, self::$open, true) ? new TaskBoardTenant($tenantSlug, $tenantSlug, $tenantSlug) : null;
    }
}
