<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Template;

use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\AllowAllTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Template\DefaultTaskTemplatePermission;
use Nowo\TaskBoardBundle\Template\TaskTemplateScope;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Audit PC-L3 (TaskBoard 2.16.0): the default template permission asks the host's tenant-aware access checker whether the
 * person reaches the tenant; the bundled checkers (which ignore the tenant) and tenant-less scopes are not affected.
 */
final class DefaultTaskTemplatePermissionTest extends TestCase
{
    public function testAHostCheckerDecidesWhichTenantsTemplatesThePersonManages(): void
    {
        $host = new class implements TenantAwareTaskBoardAccessCheckerInterface {
            /** @var list<string|null> */
            public array $asked = [];

            public function canAccess(object $user): bool
            {
                return true;
            }

            public function canCreateBoard(object $user, ?string $tenantKey = null): bool
            {
                return true;
            }

            public function canListBoards(object $user, ?string $tenantKey = null): bool
            {
                $this->asked[] = $tenantKey;

                return $tenantKey === 'acme';
            }
        };
        $permission = $this->permission($host);
        $user       = new stdClass();

        self::assertTrue($permission->canManage($user, TaskTemplateScope::tenant('acme')));
        self::assertFalse($permission->canManage($user, TaskTemplateScope::tenant('globex')));
        self::assertTrue($permission->canManage($user, TaskTemplateScope::tenant(null)));
        self::assertSame(['acme', 'globex'], $host->asked);
    }

    public function testTheBundledOrATenantBlindCheckerChangesNothing(): void
    {
        $user = new stdClass();
        self::assertTrue($this->permission(new AllowAllTaskBoardAccessChecker())->canManage($user, TaskTemplateScope::tenant('globex')));
        self::assertTrue($this->permission(null)->canManage($user, TaskTemplateScope::tenant('globex')));
        $blind = $this->createMock(TaskBoardAccessCheckerInterface::class);
        $blind->expects(self::never())->method('canListBoards');
        self::assertTrue($this->permission($blind)->canManage($user, TaskTemplateScope::tenant('globex')));
    }

    private function permission(?TaskBoardAccessCheckerInterface $checker): DefaultTaskTemplatePermission
    {
        // Resource scoping off: anyone manages a tenant's templates as far as the resource checker goes.
        $access = new TaskBoardResourceAccessChecker($this->createStub(TeamMemberRepositoryInterface::class), new NullTaskBoardTeamMembershipResolver(), scopingEnabled: false);

        return new DefaultTaskTemplatePermission($access, $this->createStub(TaskBoardRepositoryInterface::class), $checker);
    }
}
