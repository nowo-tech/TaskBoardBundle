<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Exception\TaskBoardAccessDeniedException;
use Nowo\TaskBoardBundle\Service\TaskBoardAccessInterface;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Nowo\TaskBoardBundle\View\TaskView;

/**
 * Audit YJ-M1 (2.29.0): with `tenancy.enabled` the id-based host ports reach a board only through a tenant the user may
 * open, as the pages and the Task API decide. A removed member of the organisation, a member of the board's team from
 * another organisation and a holder of a global manage role are refused; a board without a tenant is refused too.
 */
final class HostPortsTenancyTest extends FunctionalTestCase
{
    private TaskCommandsInterface $commands;

    private TaskBoardAccessInterface $access;

    protected function setUp(): void
    {
        self::$taskBoardConfig = [
            'security' => ['manage_roles' => ['ROLE_ADMIN']],
            'tenancy'  => ['enabled' => true, 'resolver' => 'app.member_tenant_resolver', 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
        parent::setUp();
        MemberTenantResolver::$members = [];
        $this->commands                = self::getContainer()->get(TaskCommandsInterface::class);
        $this->access                  = self::getContainer()->get(TaskBoardAccessInterface::class);
    }

    protected function tearDown(): void
    {
        MemberTenantResolver::$members = [];
        parent::tearDown();
    }

    public function testOnlyMembersOfTheBoardsTenantReachIt(): void
    {
        $alice                         = $this->user('alice@example.com');
        $bob                           = $this->user('bob@example.com');
        $admin                         = $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        MemberTenantResolver::$members = ['org-a' => ['owner@example.com', 'alice@example.com'], 'org-b' => ['bob@example.com', 'admin@example.com']];

        $board = $this->board('Org A', tenantKey: 'org-a');
        // Bob is on the board's team, but in another organisation.
        $team = new Team('Shared team');
        $this->em->persist($team);
        $this->em->persist(new TeamMember($team, $bob, TeamRole::Member));
        $this->em->persist(new TeamMember($team, $alice, TeamRole::Member));
        $board->setTeam($team);
        $this->em->flush();

        $task = $this->commands->createTask($this->ref($this->owner), $board->getId(), new TaskFormData('Org A work'));
        self::assertTrue($this->access->canEditTask($this->ref($alice), $task->id));
        self::assertSame('Edited', $this->commands->updateTask($this->ref($alice), $task->id, new TaskFormData('Edited'))->title);

        foreach (['cross-organisation team member' => $bob, 'global manage role' => $admin] as $who => $user) {
            $this->assertRefused($this->ref($user), $board->getId(), $task->id, $who);
        }

        // Alice leaves the organisation: refused from then on.
        MemberTenantResolver::$members['org-a'] = ['owner@example.com'];
        $this->assertRefused($this->ref($alice), $board->getId(), $task->id, 'removed member');
    }

    public function testABoardWithoutATenantIsRefused(): void
    {
        MemberTenantResolver::$members = ['org-a' => ['owner@example.com']];
        $board                         = $this->board('No tenant');
        self::assertFalse($this->access->canViewBoard($this->ref($this->owner), $board->getId()));
        $this->expectException(TaskBoardAccessDeniedException::class);
        $this->commands->createTask($this->ref($this->owner), $board->getId(), new TaskFormData('x'));
    }

    private function assertRefused(UserRef $user, string $boardId, string $taskId, string $who): void
    {
        self::assertFalse($this->access->canViewBoard($user, $boardId), $who);
        self::assertFalse($this->access->canEditBoard($user, $boardId), $who);
        self::assertFalse($this->access->canViewTask($user, $taskId), $who);
        self::assertFalse($this->access->canEditTask($user, $taskId), $who);
        self::assertSame([], $this->access->filterBoardIds($user, [$boardId]), $who);
        $calls = [
            fn (): TaskView => $this->commands->createTask($user, $boardId, new TaskFormData('x')),
            fn (): TaskView => $this->commands->updateTask($user, $taskId, new TaskFormData('x')),
            fn (): int => $this->commands->deleteTask($user, $taskId),
            fn (): string => $this->commands->addComment($user, $taskId, 'hi'),
            fn (): string => $this->commands->logTime($user, $taskId, 5, new DateTimeImmutable()),
            fn (): string => $this->commands->addColumn($user, $boardId, new BoardColumnFormData('New')),
        ];
        foreach ($calls as $index => $call) {
            try {
                $call();
                self::fail($who . ': call ' . $index . ' must be refused.');
            } catch (TaskBoardAccessDeniedException) {
            }
        }
    }

    private function ref(AppUser $user): UserRef
    {
        return UserRef::ofId((string) $user->getId());
    }
}
