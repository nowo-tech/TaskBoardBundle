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
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class TaskBoardResourceAccessCheckerTest extends TestCase
{
    public function testCreatorCanViewOwnBoardButStrangerCannot(): void
    {
        $owner    = new TestUser('1', 'owner@example.com');
        $stranger = new TestUser('2', 'stranger@example.com');
        $board    = new TaskBoard('Private', 'private', $owner);

        $checker = $this->checker();

        self::assertTrue($checker->canViewBoard($owner, $board));
        // Same id, different instance (e.g. a Doctrine proxy or reloaded user).
        self::assertTrue($checker->canViewBoard(new TestUser('1', 'owner@example.com'), $board));
        self::assertFalse($checker->canViewBoard($stranger, $board));
    }

    public function testTeamMemberCanViewTeamBoard(): void
    {
        $owner  = new TestUser('1', 'owner@example.com');
        $member = new TestUser('2', 'member@example.com');
        $team   = new Team('Eng');
        $board  = new TaskBoard('Team', 'team', $owner, team: $team);

        $repo = $this->createMock(TeamMemberRepositoryInterface::class);
        $repo->method('findByUserId')->willReturnCallback(static fn (string $id): array => $id === '2'
            ? [new TeamMember(new Team('Other'), $member, TeamRole::Member), new TeamMember($team, $member, TeamRole::Member)]
            : []);

        $checker = $this->checker(repository: $repo);

        self::assertTrue($checker->canViewBoard($member, $board));
        self::assertFalse($checker->canViewBoard(new TestUser('3', 'x@example.com'), $board));
    }

    public function testTeamResolverGrantsBoardAccess(): void
    {
        $team  = new Team('Eng');
        $board = new TaskBoard('Team', 'team', new TestUser('1', 'owner@example.com'), team: $team);

        $resolver = $this->createMock(TaskBoardTeamMembershipResolverInterface::class);
        $resolver->method('resolveTeamIds')->willReturn([42, $team->getId()]);

        self::assertTrue($this->checker(resolver: $resolver)->canViewBoard(new TestUser('9', 'r@example.com'), $board));
    }

    public function testUserWithoutIdIsOnlyMatchedByIdentity(): void
    {
        $owner = new stdClass();
        $board = new TaskBoard('Board', 'board', $owner, team: new Team('Eng'));

        $checker = $this->checker();

        self::assertTrue($checker->canViewBoard($owner, $board));
        self::assertFalse($checker->canViewBoard(new stdClass(), $board));
    }

    public function testStringableAndEmptyIdsAreHandled(): void
    {
        $stringableId = new class {
            public function getId(): Stringable
            {
                return new class implements Stringable {
                    public function __toString(): string
                    {
                        return 'uuid-1';
                    }
                };
            }
        };
        $sameId = new class {
            public function getId(): string
            {
                return 'uuid-1';
            }
        };
        $emptyId = new class {
            public function getId(): string
            {
                return '';
            }
        };
        $arrayId = new class {
            /** @return list<string> */
            public function getId(): array
            {
                return ['uuid-1'];
            }
        };

        $board   = new TaskBoard('Board', 'board', $stringableId);
        $checker = $this->checker();

        self::assertTrue($checker->canViewBoard($sameId, $board));
        self::assertFalse($checker->canViewBoard($emptyId, $board));
        self::assertFalse($checker->canViewBoard($arrayId, $board));
        self::assertFalse($checker->canViewBoard($emptyId, new TaskBoard('Team', 'team', $stringableId, team: new Team('Eng'))));
    }

    public function testManageRoleBypassesScoping(): void
    {
        $board = new TaskBoard('Private', 'private', new TestUser('1', 'owner@example.com'));

        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_TASK_ADMIN');

        self::assertTrue($this->checker(auth: $auth, manageRoles: ['ROLE_ADMIN', 'ROLE_TASK_ADMIN'])->canViewBoard(new TestUser('2', 'a@example.com'), $board));
        self::assertFalse($this->checker(auth: $auth, manageRoles: ['ROLE_ADMIN'])->canViewBoard(new TestUser('2', 'a@example.com'), $board));
    }

    public function testScopingDisabledGrantsEverything(): void
    {
        $board = new TaskBoard('Private', 'private', new TestUser('1', 'owner@example.com'));

        self::assertTrue($this->checker(scoping: false)->canViewBoard(new TestUser('2', 'a@example.com'), $board));
    }

    public function testTaskAccessFollowsBoardAndTaskParticipants(): void
    {
        $owner    = new TestUser('1', 'owner@example.com');
        $assignee = new TestUser('2', 'assignee@example.com');
        $stranger = new TestUser('3', 'stranger@example.com');
        $board    = new TaskBoard('Private', 'private', $owner);
        $task     = new Task($board, 'Secret', $owner);
        $task->addMember(new TaskMember($task, $assignee, TaskMemberRole::Assignee));
        $ownTask = new Task($board, 'Created by stranger', $stranger);

        $checker = $this->checker();

        self::assertTrue($checker->canViewTask($owner, $task));
        self::assertTrue($checker->canEditTask($owner, $task));
        self::assertTrue($checker->canViewTask($assignee, $task));
        self::assertTrue($checker->canEditTask($assignee, $task));
        self::assertFalse($checker->canViewTask($stranger, $task));
        self::assertFalse($checker->canEditTask($stranger, $task));
        self::assertTrue($checker->canViewTask($stranger, $ownTask));
    }

    public function testBoardAccessListenersCanGrantAndDeny(): void
    {
        $owner    = new TestUser('1', 'owner@example.com');
        $guest    = new TestUser('2', 'guest@example.com');
        $board    = new TaskBoard('Private', 'private', $owner);
        $received = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$received, $guest, $owner): void {
            $received[] = [$event->getSubject(), $event->getBoard(), $event->isDefaultGranted()];
            if ($event->getSubject() === $guest) {
                $event->grant();
            }
            if ($event->getSubject() === $owner) {
                $event->deny();
            }
        });

        $checker = $this->checker(dispatcher: $dispatcher);

        self::assertTrue($checker->canViewBoard($guest, $board));
        self::assertFalse($checker->canViewBoard($owner, $board));
        self::assertSame([[$guest, $board, false], [$owner, $board, true]], $received);
    }

    public function testTaskAccessListenersCanGrantDenyAndMarkReadOnly(): void
    {
        $owner    = new TestUser('1', 'owner@example.com');
        $guest    = new TestUser('2', 'guest@example.com');
        $reader   = new TestUser('3', 'reader@example.com');
        $board    = new TaskBoard('Private', 'private', $owner);
        $task     = new Task($board, 'Secret', $owner);
        $readOnly = new Task($board, 'Frozen', $owner);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($guest, $owner, $reader, $readOnly): void {
            if ($event->getSubject() === $guest) {
                $event->grant();
            }
            if ($event->getSubject() === $owner && $event->getTask() !== $readOnly) {
                $event->deny();
            }
            if ($event->getSubject() === $reader) {
                $event->grant();
                $event->markReadOnly();
            }
            if ($event->getTask() === $readOnly) {
                $event->markReadOnly();
            }
        });

        $checker = $this->checker(dispatcher: $dispatcher);

        self::assertTrue($checker->canEditTask($guest, $task));
        self::assertFalse($checker->canViewTask($owner, $task));
        self::assertTrue($checker->canViewTask($reader, $task));
        self::assertFalse($checker->canEditTask($reader, $task));
        self::assertTrue($checker->canViewTask($owner, $readOnly));
        self::assertFalse($checker->canEditTask($owner, $readOnly));
    }

    public function testFilterBoardsKeepsOnlyAccessibleBoards(): void
    {
        $owner   = new TestUser('1', 'owner@example.com');
        $other   = new TestUser('2', 'other@example.com');
        $mine    = new TaskBoard('Mine', 'mine', $owner);
        $theirs  = new TaskBoard('Theirs', 'theirs', $other);
        $checker = $this->checker();

        self::assertSame([$mine], $checker->filterBoards($owner, [$mine, $theirs]));
        self::assertSame([$theirs], $checker->filterBoards($other, [$mine, $theirs]));
    }

    public function testBoardListQueryOverrideIsStillFiltered(): void
    {
        $owner  = new TestUser('1', 'owner@example.com');
        $mine   = new TaskBoard('Mine', 'mine', $owner);
        $extra  = new TaskBoard('Extra', 'extra', $owner);
        $theirs = new TaskBoard('Theirs', 'theirs', new TestUser('2', 'other@example.com'));

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_LIST_QUERY, static function (BoardListQueryEvent $event) use ($owner, $extra, $theirs): void {
            self::assertSame($owner, $event->getSubject());
            $event->overrideList([$extra, $theirs]);
        });

        self::assertSame([$extra], $this->checker(dispatcher: $dispatcher)->filterBoards($owner, [$mine]));
    }

    /**
     * @param list<string> $manageRoles
     */
    private function checker(
        ?TeamMemberRepositoryInterface $repository = null,
        ?TaskBoardTeamMembershipResolverInterface $resolver = null,
        ?AuthorizationCheckerInterface $auth = null,
        ?EventDispatcher $dispatcher = null,
        array $manageRoles = ['ROLE_ADMIN'],
        bool $scoping = true,
    ): TaskBoardResourceAccessChecker {
        if ($repository === null) {
            $repository = $this->createMock(TeamMemberRepositoryInterface::class);
            $repository->method('findByUserId')->willReturn([]);
        }

        return new TaskBoardResourceAccessChecker(
            $repository,
            $resolver ?? new NullTaskBoardTeamMembershipResolver(),
            $auth,
            $dispatcher,
            $manageRoles,
            $scoping,
        );
    }
}
