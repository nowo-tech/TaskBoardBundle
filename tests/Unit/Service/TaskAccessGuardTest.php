<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskAccessGuard;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class TaskAccessGuardTest extends TestCase
{
    public function testAssigneeCanTrack(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);
        $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));

        $guard = new TaskAccessGuard($this->createMock(TeamMemberRepositoryInterface::class));
        self::assertTrue($guard->canTrack($user, $task));
    }

    public function testTeamMemberCanTrack(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $team  = new Team('Eng');
        $board->setTeam($team);
        $task   = new Task($board, 'Work', $user);
        $member = new TeamMember($team, $user, TeamRole::Member);

        $repo = $this->createMock(TeamMemberRepositoryInterface::class);
        $repo->method('findByUserId')->willReturn([$member]);

        self::assertTrue((new TaskAccessGuard($repo))->canTrack($user, $task));
    }

    public function testDeniedWhenNotAssigneeOnTeamBoard(): void
    {
        $owner = new TestUser('1', 'owner@example.com');
        $other = new TestUser('2', 'other@example.com');
        $board = new TaskBoard('Demo', 'demo', $owner);
        $board->setTeam(new Team('Eng'));
        $task = new Task($board, 'Work', $owner);
        $task->addMember(new TaskMember($task, $owner, TaskMemberRole::Assignee));

        $repo = $this->createMock(TeamMemberRepositoryInterface::class);
        $repo->method('findByUserId')->willReturn([]);

        self::assertFalse((new TaskAccessGuard($repo))->canTrack($other, $task));
    }

    public function testOpenBoardWithoutAssigneeAllowsTrack(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $guard = new TaskAccessGuard($this->createMock(TeamMemberRepositoryInterface::class));
        self::assertTrue($guard->canTrack($user, $task));
    }

    public function testSecondRequestWithoutResetSeesAssigneeRemovedByAnotherWorker(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $board->setTeam(new Team('Eng'));
        $task   = new Task($board, 'Work', $user);
        $member = new TaskMember($task, $user, TaskMemberRole::Assignee);
        $task->addMember($member);

        $databaseHasAssignee = true;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);
        $em->method('refresh')->willReturnCallback(static function (object $entity) use (&$databaseHasAssignee, $task, $member): void {
            if ($entity === $task && !$databaseHasAssignee) {
                $task->removeMember($member);
            }
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Task::class)->willReturn($em);

        $teamMembers = $this->createMock(TeamMemberRepositoryInterface::class);
        $teamMembers->method('findByUserId')->willReturn([]);

        $guard = new TaskAccessGuard($teamMembers, $registry);

        self::assertTrue($guard->canTrack($user, $task));

        $databaseHasAssignee = false;

        self::assertFalse($guard->canTrack($user, $task));
    }

    public function testRefreshesTaskAndBoardBeforeDeciding(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $refreshed = [];
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);
        $em->method('refresh')->willReturnCallback(static function (object $entity) use (&$refreshed): void {
            $refreshed[] = $entity;
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        (new TaskAccessGuard($this->createMock(TeamMemberRepositoryInterface::class), $registry))->canTrack($user, $task);

        self::assertSame([$task, $board], $refreshed);
    }

    public function testSkipsRefreshForUnmanagedTaskOrBoardOrClosedManager(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'Work', $user);

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::never())->method('refresh');

        $unmanagedBoard = $this->createMock(EntityManagerInterface::class);
        $unmanagedBoard->method('isOpen')->willReturn(true);
        $unmanagedBoard->method('contains')->willReturnCallback(static fn (object $entity): bool => $entity === $task);
        $unmanagedBoard->expects(self::once())->method('refresh')->with($task);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnOnConsecutiveCalls($closed, $unmanagedBoard, null);

        $guard = new TaskAccessGuard($this->createMock(TeamMemberRepositoryInterface::class), $registry);

        self::assertTrue($guard->canTrack($user, $task));
        self::assertTrue($guard->canTrack($user, $task));
        self::assertTrue($guard->canTrack($user, $task));
    }
}
