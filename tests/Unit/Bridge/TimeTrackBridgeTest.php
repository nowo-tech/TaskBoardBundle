<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Bridge;

use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTeamContextProvider;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Service\TaskAccessGuard;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TimeTrackBundle\Dto\TaskListQuery;
use PHPUnit\Framework\TestCase;

final class TimeTrackBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(\Nowo\TimeTrackBundle\Integration\TaskProviderInterface::class)) {
            self::markTestSkipped('nowo-tech/time-track-bundle is not installed.');
        }
    }

    public function testTaskProviderFindsTrackableTask(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'API', $user);

        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn($task);
        $repo->method('findTrackableForUser')->willReturn([$task]);

        $guard    = new TaskAccessGuard($this->createMock(TeamMemberRepositoryInterface::class));
        $provider = new TaskBoardTaskProvider($repo, $guard);

        $ref = $provider->findForUser($task->getId(), $user);
        self::assertNotNull($ref);
        self::assertSame('API', $ref->title);

        $list = $provider->listTrackableForUser($user, new TaskListQuery());
        self::assertCount(1, $list);
        self::assertTrue($provider->canUserTrack($user, $task->getId()));
    }

    public function testTaskProviderReturnsNullWhenAccessDenied(): void
    {
        $user = new TestUser('1', 'dev@example.com');
        $repo = $this->createMock(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $guard    = new TaskAccessGuard($this->createMock(TeamMemberRepositoryInterface::class));
        $provider = new TaskBoardTaskProvider($repo, $guard);

        self::assertNull($provider->findForUser('missing', $user));
        self::assertFalse($provider->canUserTrack($user, 'missing'));
    }

    public function testTeamContextProviderAggregatesMemberships(): void
    {
        $manager = new TestUser('1', 'manager@example.com');
        $member  = new TestUser('2', 'member@example.com');
        $team    = new Team('Engineering');
        $tm      = new TeamMember($team, $member, TeamRole::Member);

        $repo = $this->createMock(TeamMemberRepositoryInterface::class);
        $repo->method('findByUserId')->willReturn([$tm]);
        $repo->method('isManagerOf')->willReturn(true);
        $repo->method('findMembersManagedBy')->willReturn([$tm]);

        $provider = new TaskBoardTeamContextProvider($repo);

        self::assertSame([$team->getId()], $provider->getTeamIdsForUser($member));
        self::assertTrue($provider->isManagerOf($manager, $member));
        self::assertSame(['2'], $provider->getManagedUserIds($manager));
    }
}
