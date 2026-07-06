<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Service;

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
}
