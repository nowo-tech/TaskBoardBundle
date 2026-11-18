<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Menu;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Menu\TaskMenuBuilder;
use Nowo\TaskBoardBundle\Menu\TaskMenuContext;
use Nowo\TaskBoardBundle\Menu\TaskTimerStart;
use Nowo\TaskBoardBundle\Menu\TaskTimerStarterInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Twig\TaskMenuTwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Since 2.22.0: the board page decides who may edit each of its tasks with one board decision
 * (`TaskBoardResourceAccessChecker::taskEditDecider()`), and answers exactly what `canEditTask()` answers, whatever the
 * host's TASK_ACCESS_CHECK and BOARD_ACCESS_CHECK listeners say.
 */
final class TaskEditDeciderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function decisions(): iterable
    {
        foreach (['none', 'deny', 'grant', 'grant-read-only'] as $task) {
            foreach (['none', 'deny', 'grant', 'read-only'] as $board) {
                foreach ([true, false] as $creator) {
                    yield $task . ' / ' . $board . ' / ' . ($creator ? 'creator' : 'participant') => [$task, $board, $creator];
                }
            }
        }
    }

    #[DataProvider('decisions')]
    public function testTheDeciderAnswersAsCanEditTask(string $taskDecision, string $boardDecision, bool $creator): void
    {
        $user       = new TestUser('u1', 'ana');
        $other      = new TestUser('u2', 'bob');
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($taskDecision): void {
            match ($taskDecision) {
                'deny'            => $event->deny(),
                'grant'           => $event->grant(),
                'grant-read-only' => [$event->grant(), $event->markReadOnly()],
                default           => null,
            };
        });
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($boardDecision): void {
            match ($boardDecision) {
                'deny'      => $event->deny(),
                'grant'     => $event->grant(),
                'read-only' => [$event->grant(), $event->markReadOnly()],
                default     => null,
            };
        });
        $access = new TaskBoardResourceAccessChecker($this->createStub(TeamMemberRepositoryInterface::class), new NullTaskBoardTeamMembershipResolver(), eventDispatcher: $dispatcher);
        $board  = new TaskBoard('B', 'b', $creator ? $user : $other);
        $task   = new Task($board, 'T', $other);
        if (!$creator) {
            $task->addMember(new TaskMember(task: $task, user: $user, memberRole: TaskMemberRole::Assignee));
        }
        $stranger = new Task($board, 'Not mine', $other);

        $decide = $access->taskEditDecider($user, $board);
        self::assertSame($access->canEditTask($user, $task), $decide($task));
        self::assertSame($access->canEditTask($user, $stranger), $decide($stranger));
    }

    public function testTheMenusOfAPageWithoutAUserOrAMenuBuilderAreNone(): void
    {
        $access = new TaskBoardResourceAccessChecker($this->createStub(TeamMemberRepositoryInterface::class), new NullTaskBoardTeamMembershipResolver());
        $board  = new TaskBoard('B', 'b', null);
        $task   = new Task($board, 'T', null);

        self::assertNull((new TaskMenuBuilder($access))->context($board), 'no token storage');
        self::assertNull((new TaskMenuBuilder($access, new TokenStorage()))->context($board), 'no token');
        $twig = new TaskMenuTwigExtension();
        self::assertNull($twig->context($board));
        self::assertNull($twig->taskMenu($task, null));
        self::assertNull($twig->menuForm('/menu'));
        self::assertSame(['nowo_task_board_menu_context', 'nowo_task_board_task_menu', 'nowo_task_board_context_menus', 'nowo_task_board_menu_form'], array_map(static fn ($function): string => $function->getName(), $twig->getFunctions()));
    }

    public function testATaskOfAnotherBoardGetsNoMenuFromThisPage(): void
    {
        $user    = new TestUser('u1', 'ana');
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $access = new TaskBoardResourceAccessChecker($this->createStub(TeamMemberRepositoryInterface::class), new NullTaskBoardTeamMembershipResolver());
        $timer  = new class implements TaskTimerStarterInterface {
            public function isAvailable(): bool
            {
                return true;
            }

            public function start(object $user, Task $task): TaskTimerStart
            {
                return TaskTimerStart::Started;
            }
        };
        $builder = new TaskMenuBuilder($access, $storage, true, $timer);
        $board   = new TaskBoard('B', 'b', $user);
        $context = $builder->context($board);
        self::assertInstanceOf(TaskMenuContext::class, $context);
        self::assertTrue($context->timerEnabled && $context->copyEnabled && $context->canManageBoard);

        $twig = new TaskMenuTwigExtension($builder);
        $mine = new Task($board, 'Mine', $user);
        self::assertTrue($twig->taskMenu($mine, $context)?->timer);
        self::assertTrue($twig->taskMenu($mine, $context)?->copy);
        self::assertNull($twig->taskMenu(new Task(new TaskBoard('Other', 'o', $user), 'Theirs', $user), $context));
        // An archived task: only what reads (open, copy link) and the manager's delete.
        $mine->archive();
        $menu = $builder->forTask($mine, $context);
        self::assertSame([true, false, false, [], [], [], false, false, true], [$menu->edit, $menu->assignMe, $menu->tags, $menu->statuses, $menu->priorities, $menu->dueChoices, $menu->timer, $menu->archive, $menu->delete]);
    }
}
