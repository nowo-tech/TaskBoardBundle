<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ScriptedTenantResolver;

/**
 * Audit IA-L2: with tenancy the guard asks the bundle's tenant resolver, as the UI does, so a host whose workspace hook is
 * too permissive does not open another tenant's boards.
 */
final class ApiTenancyTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$hostConfig = ['tenancy' => [
            'enabled'      => true,
            'resolver'     => 'app.tenant_resolver',
            'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']],
        ]];
        ScriptedTenantResolver::$open = ['mine'];
        parent::setUp();
        // A permissive workspace: it answers for every tenant the test names.
        ApiTestHost::$tenants = ['mine', 'theirs', null];
    }

    protected function tearDown(): void
    {
        ScriptedTenantResolver::$open = [];
        parent::tearDown();
    }

    public function testATenantTheResolverRefusesIsOutOfReachEvenIfTheWorkspaceAnswersForIt(): void
    {
        $mine      = $this->board('Mine', tenantKey: 'mine');
        $theirs    = $this->board('Theirs', tenantKey: 'theirs');
        $loose     = $this->board('Loose');
        $theirTask = $this->task($theirs, 'Their task');
        $principal = new TaskApiPrincipal($this->owner, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name');

        self::assertSame($mine->getId(), $this->guard()->board($principal, $mine->getId(), TaskApiCapability::ReadOwn)->getId());
        foreach ([fn () => $this->guard()->board($principal, $theirs->getId(), TaskApiCapability::ReadOwn), fn () => $this->guard()->task($principal, $theirTask->getId(), TaskApiCapability::ReadOwn), fn () => $this->guard()->board($principal, $loose->getId(), TaskApiCapability::ReadOwn)] as $call) {
            try {
                $call();
                self::fail('404 expected');
            } catch (TaskApiRefusal $refusal) {
                self::assertSame('ACCESS_001', $refusal->ecode);
            }
        }
    }

    public function testSpacesAndTeamListsLeaveOutWhatTheResolverRefuses(): void
    {
        $mine = $this->task($this->board('Mine', tenantKey: 'mine'), 'Mine');
        $this->task($this->board('Theirs', tenantKey: 'theirs'), 'Theirs');
        $this->issueToken();

        $this->get('/api/tasks/v2/team/team-1/space');
        self::assertSame(['space-mine'], $this->idsOf('spaces'));
        $this->get('/api/tasks/v2/team/team-1/task');
        self::assertSame([$mine->getId()], $this->idsOf('tasks'));
        foreach (['/api/tasks/v2/space/space-theirs', '/api/tasks/v2/space/space-theirs/list', '/api/tasks/v2/space/space-none'] as $path) {
            $this->get($path);
            $this->assertRefused(404, 'ACCESS_001');
        }
    }

    private function guard(): TaskApiGuard
    {
        $guard = self::getContainer()->get(TaskApiGuard::class);
        self::assertInstanceOf(TaskApiGuard::class, $guard);

        return $guard;
    }
}
