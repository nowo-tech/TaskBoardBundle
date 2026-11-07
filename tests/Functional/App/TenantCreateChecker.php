<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;

/**
 * Since 1.24: a host access checker whose create check depends on the tenant (e.g. a project permission): only the
 * tenant `acme` (or no tenant) accepts new boards; every user may open and list.
 */
final class TenantCreateChecker implements TenantAwareTaskBoardAccessCheckerInterface
{
    /** Since 2.0.0: the tenant whose board list this checker refuses. */
    public const UNLISTED_TENANT = 'hidden';

    /** @var list<string|null> The tenant keys canCreateBoard() was asked about. */
    public array $asked = [];

    public function canAccess(object $user): bool
    {
        return true;
    }

    public function canCreateBoard(object $user, ?string $tenantKey = null): bool
    {
        $this->asked[] = $tenantKey;

        return $tenantKey === null || $tenantKey === 'acme';
    }

    public function canListBoards(object $user, ?string $tenantKey = null): bool
    {
        return $tenantKey !== self::UNLISTED_TENANT;
    }
}
