<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Security;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\BoardViewReach;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.25.0: boardViewDecider() and taskViewDecider() answer what canViewBoard() and canViewTask() answer, with the
 * user's teams and roles read once.
 */
final class SearchDecidersTest extends TestCase
{
    public function testTheBoardDeciderAnswersAsCanViewBoardAndReadsTeamsOnce(): void
    {
        $owner  = new TestUser('1', 'owner@example.com');
        $member = new TestUser('2', 'member@example.com');
        $team   = new Team('Eng');
        $other  = new Team('Other');
        $boards = [
            new TaskBoard('Own', 'own', $member),
            new TaskBoard('Team', 'team', $owner, team: $team),
            new TaskBoard('Resolved', 'resolved', $owner, team: $other),
            new TaskBoard('Foreign', 'foreign', $owner, team: new Team('Foreign')),
            new TaskBoard('No team', 'none', $owner),
        ];
        $repository = $this->createMock(TeamMemberRepositoryInterface::class);
        $repository->expects(self::once())->method('findByUserId')->with('2')->willReturn([new TeamMember($team, $member, TeamRole::Member)]);
        $resolver = $this->createMock(TaskBoardTeamMembershipResolverInterface::class);
        $resolver->expects(self::once())->method('resolveTeamIds')->willReturn([$other->getId()]);
        $checker = new TaskBoardResourceAccessChecker($repository, $resolver);

        $decide = $checker->boardViewDecider($member);

        self::assertSame([true, true, true, false, false], array_map($decide, $boards));
    }

    public function testTheBoardDeciderAsksManageRolesOnceAndOnlyWhenAllowed(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::once())->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);
        $checker = new TaskBoardResourceAccessChecker($this->noTeams(), $this->noResolver(), $auth);
        $user    = new TestUser('9', 'admin@example.com');
        $a       = new TaskBoard('A', 'a', new TestUser('1', 'x@example.com'));
        $b       = new TaskBoard('B', 'b', new TestUser('1', 'x@example.com'));

        $decide = $checker->boardViewDecider($user);
        self::assertTrue($decide($a));
        self::assertTrue($decide($b));
        self::assertFalse($checker->boardViewDecider($user, false)($a));
    }

    public function testTheBoardDeciderFollowsBoardAccessCheckAndScopingOff(): void
    {
        $owner      = new TestUser('1', 'owner@example.com');
        $denied     = new TaskBoard('Denied', 'denied', $owner);
        $granted    = new TaskBoard('Granted', 'granted', new TestUser('5', 'y@example.com'));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($denied): void {
            $event->getBoardId() === $denied->getId() ? $event->deny() : $event->grant();
        });
        $checker = new TaskBoardResourceAccessChecker($this->noTeams(), $this->noResolver(), null, $dispatcher);

        $decide = $checker->boardViewDecider($owner);
        self::assertFalse($decide($denied));
        self::assertTrue($decide($granted));

        $open = new TaskBoardResourceAccessChecker($this->noTeams(), $this->noResolver(), scopingEnabled: false);
        self::assertTrue($open->boardViewDecider(new stdClass())(new TaskBoard('Any', 'any', $owner, team: new Team('T'))));
    }

    public function testTheTaskDeciderFollowsTaskAccessCheckThenTheBoardThenParticipation(): void
    {
        $owner    = new TestUser('1', 'owner@example.com');
        $viewer   = new TestUser('2', 'viewer@example.com');
        $visible  = new TaskBoard('Visible', 'visible', $owner);
        $hidden   = new TaskBoard('Hidden', 'hidden', $owner);
        $denied   = new Task($visible, 'Denied', $owner);
        $plain    = new Task($visible, 'Plain', $owner);
        $granted  = new Task($hidden, 'Granted', $owner);
        $foreign  = new Task($hidden, 'Foreign', $owner);
        $assigned = new Task($hidden, 'Assigned', $owner);
        $assigned->addMember(new TaskMember($assigned, $viewer, TaskMemberRole::Assignee));
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($denied, $granted): void {
            if ($event->getTaskId() === $denied->getId()) {
                $event->deny();
            }
            if ($event->getTaskId() === $granted->getId()) {
                $event->grant();
            }
        });
        $checker = new TaskBoardResourceAccessChecker($this->noTeams(), $this->noResolver(), null, $dispatcher);
        $asked   = 0;
        $decide  = $checker->taskViewDecider($viewer, static function (TaskBoard $board) use ($visible, &$asked): bool {
            ++$asked;

            return $board === $visible;
        });

        self::assertSame([false, true, true, false, true], array_map($decide, [$denied, $plain, $granted, $foreign, $assigned]));
        self::assertSame(2, $asked, 'each board once');
    }

    public function testTheDefaultReachSaysWhetherAListenerMayGrant(): void
    {
        $user       = new TestUser('2', 'member@example.com');
        $team       = new Team('Eng');
        $repository = $this->createMock(TeamMemberRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([new TeamMember($team, $user, TeamRole::Member)]);
        $dispatcher = new EventDispatcher();

        $quiet = (new TaskBoardResourceAccessChecker($repository, $this->noResolver(), null, $dispatcher))->defaultBoardReach($user);
        self::assertEquals(new BoardViewReach(false, [$team->getId()], false), $quiet);

        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (): void {
        });
        self::assertTrue((new TaskBoardResourceAccessChecker($repository, $this->noResolver(), null, $dispatcher))->defaultBoardReach($user)->listenersMayGrant);
        self::assertTrue((new TaskBoardResourceAccessChecker($repository, $this->noResolver(), null, $this->createMock(EventDispatcherInterface::class)))->defaultBoardReach($user)->listenersMayGrant, 'a dispatcher that cannot say: assume a listener');
        self::assertFalse((new TaskBoardResourceAccessChecker($repository, $this->noResolver()))->defaultBoardReach($user)->listenersMayGrant);
        self::assertEquals(new BoardViewReach(true, [], false), (new TaskBoardResourceAccessChecker($repository, $this->noResolver(), scopingEnabled: false))->defaultBoardReach($user));

        $reach  = new BoardViewReach(false, [$team->getId()], false);
        $decide = (new TaskBoardResourceAccessChecker($this->createMock(TeamMemberRepositoryInterface::class), $this->noResolver()))->boardViewDecider($user, true, $reach);
        self::assertTrue($decide(new TaskBoard('Team', 'team', new TestUser('1', 'o@example.com'), team: $team)), 'the given reach, no query');
    }

    private function noTeams(): TeamMemberRepositoryInterface
    {
        $repository = $this->createMock(TeamMemberRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([]);

        return $repository;
    }

    private function noResolver(): TaskBoardTeamMembershipResolverInterface
    {
        $resolver = $this->createMock(TaskBoardTeamMembershipResolverInterface::class);
        $resolver->method('resolveTeamIds')->willReturn([]);

        return $resolver;
    }
}
