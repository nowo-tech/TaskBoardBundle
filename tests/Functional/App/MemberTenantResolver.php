<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Since 2.23.1: a host's tenant resolver that opens a tenant (slug = key) only to its members, as Beacon opens a project
 * only to its members: any other user, and an unknown slug, get null (404).
 */
final class MemberTenantResolver implements TaskBoardTenantResolverInterface
{
    /** @var array<string, list<string>> tenant slug => the user identifiers of its members */
    public static array $members = [];

    public function resolve(string $tenantSlug, object $user): ?TaskBoardTenant
    {
        $identifier = $user instanceof UserInterface ? $user->getUserIdentifier() : null;

        return in_array($identifier, self::$members[$tenantSlug] ?? [], true) ? new TaskBoardTenant($tenantSlug, $tenantSlug, $tenantSlug) : null;
    }
}
