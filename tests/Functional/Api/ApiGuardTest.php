<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 213: level ∩ the person's access ∩ where, on every id-based target; every denial asserted.
 */
final class ApiGuardTest extends ApiTestCase
{
    private const TENANT = 'tenant-a';

    private TaskBoard $board;

    private Task $task;

    protected function setUp(): void
    {
        static::$api        = ['host_decides_access' => true];
        static::$hostConfig = ['security' => ['resource_scoping' => false]];
        parent::setUp();
        ApiTestHost::$tenants = [self::TENANT];
        $this->board          = $this->board(tenantKey: self::TENANT);
        $this->task           = $this->task($this->board, 'Owned by the owner');
    }

    public function testAMissingTargetIsNotFound(): void
    {
        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->board($this->principal(), 'nothing', TaskApiCapability::ReadOwn));
        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->task($this->principal(), 'nothing', TaskApiCapability::ReadOwn));
    }

    public function testOutsideWhereIsNotFoundWhateverTheLevel(): void
    {
        $other = TaskApiWhere::limitedTo(['tenant-b'], ['another-board']);
        foreach (TaskApiLevel::cases() as $level) {
            $principal = $this->principal($level, $other);
            $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->board($principal, $this->board->getId(), TaskApiCapability::ReadOwn));
            $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->task($principal, $this->task->getId(), TaskApiCapability::ReadOwn));
        }
    }

    public function testATenantTheHostForbidsIsNotFound(): void
    {
        $principal = $this->principal(where: TaskApiWhere::everything([self::TENANT]));

        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->board($principal, $this->board->getId(), TaskApiCapability::ReadOwn));
    }

    public function testNamingABoardOrItsTenantInWhereReachesIt(): void
    {
        foreach ([TaskApiWhere::limitedTo([self::TENANT], []), TaskApiWhere::limitedTo([], [$this->board->getId()])] as $where) {
            self::assertSame($this->board->getId(), $this->guard()->board($this->principal(where: $where), $this->board->getId(), TaskApiCapability::ReadOwn)->getId());
        }
    }

    public function testATenantWithoutASpaceIsNotFound(): void
    {
        ApiTestHost::$tenants = [];

        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->board($this->principal(), $this->board->getId(), TaskApiCapability::ReadOwn));
    }

    public function testNoViewAccessIsNotFoundAndARemovedMemberStopsAtOnce(): void
    {
        $principal = $this->principal();
        self::assertSame($this->board->getId(), $this->guard()->board($principal, $this->board->getId(), TaskApiCapability::ReadOwn)->getId());

        // The next request: the host's listener no longer lets them in.
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->deny();
        });

        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->board($principal, $this->board->getId(), TaskApiCapability::ReadOwn));
        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->task($principal, $this->task->getId(), TaskApiCapability::ReadOwn));
    }

    public function testATaskTheHostDeniesIsNotFound(): void
    {
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->deny();
        });

        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->task($this->principal(), $this->task->getId(), TaskApiCapability::ReadOwn));
    }

    public function testMyTasksSeesOnlyWhatItCreatedOrIsAssigned(): void
    {
        $other     = $this->user('other@example.com');
        $principal = new TaskApiPrincipal($other, TaskApiLevel::MyTasks, TaskApiWhere::everything(), 'id', 'name');

        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->task($principal, $this->task->getId(), TaskApiCapability::ReadOwn));

        // Watching does not count.
        $this->member($this->task, $other, TaskMemberRole::Watcher);
        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->task($principal, $this->task->getId(), TaskApiCapability::ReadOwn));

        $this->member($this->task, $other, TaskMemberRole::Assignee);
        self::assertSame($this->task->getId(), $this->guard()->task($principal, $this->task->getId(), TaskApiCapability::ReadOwn)->getId());

        // Their own task, created by them.
        $mine = new Task(board: $this->board, title: 'Mine', creator: $other);
        $this->em->persist($mine);
        $this->em->flush();
        self::assertSame($mine->getId(), $this->guard()->task($principal, $mine->getId(), TaskApiCapability::WorkOnTask)->getId());
    }

    public function testAReadTokenSeesAnothersTaskAndAnErasedCreatorIsNobody(): void
    {
        $other = $this->user('other@example.com');
        self::assertSame($this->task->getId(), $this->guard()->task(new TaskApiPrincipal($other, TaskApiLevel::Read, TaskApiWhere::everything(), 'id', 'name'), $this->task->getId(), TaskApiCapability::ReadEverything)->getId());

        self::assertFalse($this->guard()->isOwn($other, new Task(board: $this->board, title: 'Orphan', creator: null)));
        self::assertTrue($this->guard()->isOwn($this->owner, new Task(board: $this->board, title: 'Same object', creator: $this->owner)));
        self::assertFalse($this->guard()->isOwn(new class {}, new Task(board: $this->board, title: 'No ids', creator: $this->owner)));
    }

    public function testAPlanWithoutTheModuleIsARefusalOnlyForWhatCanBeSeen(): void
    {
        ApiTestHost::$planRefusesTenants = [self::TENANT];

        $this->assertDenied(403, 'PLAN_001', fn () => $this->guard()->board($this->principal(), $this->board->getId(), TaskApiCapability::ReadOwn));
        $this->assertDenied(403, 'PLAN_001', fn () => $this->guard()->task($this->principal(), $this->task->getId(), TaskApiCapability::ReadOwn));

        // Out of reach comes first.
        $outside = $this->principal(where: TaskApiWhere::limitedTo([], []));
        $this->assertDenied(404, 'ACCESS_001', fn () => $this->guard()->board($outside, $this->board->getId(), TaskApiCapability::ReadOwn));
    }

    public function testALevelThatMayNotIsARefusalForWhatItCanSee(): void
    {
        $read = $this->principal(TaskApiLevel::Read);
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->task($read, $this->task->getId(), TaskApiCapability::WorkOnTask));
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->task($read, $this->task->getId(), TaskApiCapability::Comment));
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->board($read, $this->board->getId(), TaskApiCapability::EditTasks));

        $mine = $this->principal(TaskApiLevel::MyTasks);
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->task($mine, $this->task->getId(), TaskApiCapability::EditTasks));
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->task($mine, $this->task->getId(), TaskApiCapability::ReadEverything));
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->board($mine, $this->board->getId(), TaskApiCapability::EditTasks));

        $tasks = $this->principal(TaskApiLevel::Tasks);
        $this->assertDenied(403, 'TOKEN_001', fn () => $this->guard()->board($tasks, $this->board->getId(), TaskApiCapability::ManageBoards));
        self::assertSame($this->task->getId(), $this->guard()->task($tasks, $this->task->getId(), TaskApiCapability::DeleteTask)->getId());
    }

    public function testAReadOnlyPersonIsARefusalWhateverTheToken(): void
    {
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->grant();
                $event->markReadOnly();
            } else {
                $event->deny();
            }
        });
        $full = $this->principal();

        self::assertSame($this->board->getId(), $this->guard()->board($full, $this->board->getId(), TaskApiCapability::ReadEverything)->getId());
        $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->board($full, $this->board->getId(), TaskApiCapability::EditTasks));
        $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->board($full, $this->board->getId(), TaskApiCapability::ManageBoards));
        $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->task($full, $this->task->getId(), TaskApiCapability::WorkOnTask));
        $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->task($full, $this->task->getId(), TaskApiCapability::EditTasks));
        $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->task($full, $this->task->getId(), TaskApiCapability::DeleteTask));
        $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->task($full, $this->task->getId(), TaskApiCapability::ManageBoards));
        // readers_can_comment is on by default.
        self::assertSame($this->task->getId(), $this->guard()->task($full, $this->task->getId(), TaskApiCapability::Comment)->getId());
    }

    public function testACapabilityOfATaskIsNotGrantedOnABoard(): void
    {
        foreach ([TaskApiCapability::WorkOnTask, TaskApiCapability::Comment, TaskApiCapability::DeleteTask] as $capability) {
            $this->assertDenied(403, 'ACCESS_002', fn () => $this->guard()->board($this->principal(), $this->board->getId(), $capability));
        }
    }

    private function principal(TaskApiLevel $level = TaskApiLevel::Full, ?TaskApiWhere $where = null): TaskApiPrincipal
    {
        return new TaskApiPrincipal($this->owner, $level, $where ?? TaskApiWhere::everything(), 'id', 'name');
    }

    private function member(Task $task, AppUser $user, TaskMemberRole $role): void
    {
        $task->addMember(new TaskMember(task: $task, user: $user, memberRole: $role));
        $this->em->flush();
    }

    private function assertDenied(int $status, string $ecode, callable $call): void
    {
        try {
            $call();
            self::fail('TaskApiRefusal ' . $ecode . ' expected');
        } catch (TaskApiRefusal $refusal) {
            self::assertSame([$status, $ecode], [$refusal->status, $refusal->ecode]);
        }
    }

    private function guard(): TaskApiGuard
    {
        $guard = self::getContainer()->get(TaskApiGuard::class);
        self::assertInstanceOf(TaskApiGuard::class, $guard);

        return $guard;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
