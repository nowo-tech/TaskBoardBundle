<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Bridge;

use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskDirectory;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTeamContextProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTeamDirectory;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackScopeKeys;
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
use Nowo\TimeTrackBundle\Port\TaskDirectoryGate;
use Nowo\TimeTrackBundle\Port\TeamDirectoryGate;
use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Since 2.28.0: the TimeTrack bridge on TimeTrack's framework-free 1.23 twins. Through TimeTrack's own gate they answer
 * exactly as the deprecated 1.x providers; a reference without a user object or an id sees nothing.
 */
final class TimeTrackTwinBridgeTest extends TestCase
{
    public function testTheTaskDirectoryAnswersAsTheTaskProvider(): void
    {
        $user  = new TestUser('1', 'dev@example.com');
        $other = new TestUser('2', 'other@example.com');
        $board = new TaskBoard('Demo', 'demo', $user);
        $task  = new Task($board, 'API', $user);
        $repo  = $this->createStub(TaskRepositoryInterface::class);
        $repo->method('findById')->willReturnCallback(static fn (string $id): ?Task => $id === $task->getId() ? $task : null);
        $repo->method('findTrackableForUser')->willReturnCallback(static fn (string $userId): array => $userId === '1' ? [$task] : []);
        $guard = new TaskAccessGuard($this->createStub(TeamMemberRepositoryInterface::class));
        $twin  = new TaskDirectoryGate(new TaskBoardTaskDirectory($repo, $guard));
        $old   = new TaskBoardTaskProvider($repo, $guard);

        foreach ([$user, $other] as $who) {
            self::assertEquals($old->findForUser($task->getId(), $who), $twin->findForUser($task->getId(), $who));
            self::assertEquals($old->canUserTrack($who, $task->getId()), $twin->canUserTrack($who, $task->getId()));
            self::assertEquals($old->listTrackableForUser($who, new TaskListQuery()), $twin->listTrackableForUser($who, new TaskListQuery()));
            self::assertNull($twin->findForUser('missing', $who));
        }
        self::assertSame('API', $twin->findForUser($task->getId(), $user)?->title);

        $directory = new TaskBoardTaskDirectory($repo, $guard);
        $byId      = TimeTrackUserRef::ofId(TestUser::class, '1');
        self::assertNull($directory->findTaskFor($task->getId(), $byId), 'no user object: no access decision');
        self::assertFalse($directory->canTrackTask($byId, $task->getId()));
        self::assertCount(1, $directory->listTrackableFor($byId, new TaskListQuery()), 'the list needs the id only');
        self::assertSame([], $directory->listTrackableFor(new TimeTrackUserRef(TestUser::class, null), new TaskListQuery()));
    }

    public function testTheTeamDirectoryAnswersAsTheTeamContextProvider(): void
    {
        $manager = new TestUser('1', 'manager@example.com');
        $member  = new TestUser('2', 'member@example.com');
        $team    = new Team('Engineering');
        $tm      = new TeamMember($team, $member, TeamRole::Member);
        $repo    = $this->createMock(TeamMemberRepositoryInterface::class);
        $repo->method('findByUserId')->willReturnCallback(static fn (string $id): array => $id === '2' ? [$tm, $tm] : []);
        $repo->method('isManagerOf')->willReturnCallback(static fn (string $a, string $b): bool => $a === '1' && $b === '2');
        $repo->method('findMembersManagedBy')->willReturnCallback(static fn (string $id): array => $id === '1' ? [$tm, $tm, new TeamMember($team, new stdClass(), TeamRole::Member)] : []);
        $twin = new TeamDirectoryGate(new TaskBoardTeamDirectory($repo));
        $old  = new TaskBoardTeamContextProvider($repo);

        foreach ([[$manager, $member], [$member, $manager]] as [$a, $b]) {
            self::assertSame($old->getTeamIdsForUser($a), $twin->getTeamIdsForUser($a));
            self::assertSame($old->isManagerOf($a, $b), $twin->isManagerOf($a, $b));
            self::assertSame($old->getManagedUserIds($a), $twin->getManagedUserIds($a));
        }
        self::assertSame([$team->getId()], $twin->getTeamIdsForUser($member));
        self::assertSame(['2'], $twin->getManagedUserIds($manager));

        $noId      = new TimeTrackUserRef(TestUser::class, null);
        $directory = new TaskBoardTeamDirectory($repo);
        self::assertSame([], $directory->teamIdsOf($noId));
        self::assertFalse($directory->manages($noId, TimeTrackUserRefFactory::build($member)));
        self::assertFalse($directory->manages(TimeTrackUserRefFactory::build($manager), $noId));
        self::assertSame([], $directory->managedUserIdsOf($noId));
    }

    public function testTheScopeKeyComesFromEitherScopePort(): void
    {
        $user   = new TestUser('7', 'ana@example.com');
        $legacy = $this->createMock(TimesheetScopeProviderInterface::class);
        $legacy->expects(self::once())->method('scopeKeyForTask')->with($user, 'task-1', 'board-1')->willReturn('org-a');
        $policy = $this->createMock(TimesheetScopePolicyInterface::class);
        $policy->expects(self::once())->method('scopeKeyForUserTask')->willReturnCallback(static fn (TimeTrackUserRef $ref, string $task, ?string $board): ?string => $ref->user() === $user && $ref->id === '7' && $task === 'task-1' && $board === null ? 'org-b' : null);

        self::assertSame('org-a', TimeTrackScopeKeys::forTask($legacy, $user, 'task-1', 'board-1'));
        self::assertSame('org-b', TimeTrackScopeKeys::forTask($policy, $user, 'task-1', null));
        self::assertNull(TimeTrackScopeKeys::forTask($policy, new stdClass(), 'task-1', null), 'not a security user');
        self::assertNull(TimeTrackScopeKeys::forTask(null, $user, 'task-1', null), 'no scope port');
        self::assertNull(TimeTrackScopeKeys::forTask(new stdClass(), $user, 'task-1', null), 'neither interface');

        self::assertSame(TimesheetScopeProviderInterface::class, TimeTrackScopeKeys::serviceId());
        self::assertSame(TimesheetScopePolicyInterface::class, TimeTrackScopeKeys::serviceId('Nowo\\TimeTrackBundle\\Timesheet\\MissingScopeProvider'));
    }
}
