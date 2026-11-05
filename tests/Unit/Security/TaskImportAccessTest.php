<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Security;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 1.25: importing tasks (a file into the board or under a task, and the provider connection) is its own
 * BOARD_ACCESS_CHECK decision, `import_tasks`, granted by default and narrowed by host listeners (Beacon's
 * `project.tasks.import`).
 */
final class TaskImportAccessTest extends TestCase
{
    public function testImportIsGrantedByDefaultToEditorsAndNarrowedByAListener(): void
    {
        $owner    = new TestUser('1', 'owner@example.com');
        $stranger = new TestUser('2', 'stranger@example.com');
        $board    = new TaskBoard('Board', 'board', $owner);
        $actions  = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use (&$actions): void {
            $actions[] = $event->getAction();
        });
        $checker = $this->checker($dispatcher);

        self::assertTrue($checker->canImportTasks($owner, $board));
        self::assertTrue($checker->canManageBoardConnection($owner, $board));
        self::assertFalse($checker->canImportTasks($stranger, $board));
        self::assertContains(BoardAccessCheckEvent::ACTION_IMPORT_TASKS, $actions);
        self::assertSame('import_tasks', BoardAccessCheckEvent::ACTION_IMPORT_TASKS);

        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_IMPORT_TASKS) {
                $event->deny();
            }
        });

        self::assertFalse($checker->canImportTasks($owner, $board));
        // The connection imports tasks (and people and time): the same decision applies.
        self::assertFalse($checker->canManageBoardConnection($owner, $board));
        // Viewing and editing are untouched.
        self::assertTrue($checker->canEditBoard($owner, $board));
    }

    public function testATaskParticipantImportsSubtasksUnlessImportIsDenied(): void
    {
        $owner       = new TestUser('1', 'owner@example.com');
        $participant = new TestUser('2', 'participant@example.com');
        $stranger    = new TestUser('3', 'stranger@example.com');
        $board       = new TaskBoard('Board', 'board', $owner);
        $task        = new Task(board: $board, title: 'Mine', creator: $owner);
        $task->addMember(new TaskMember($task, $participant, TaskMemberRole::Watcher));

        $dispatcher = new EventDispatcher();
        $checker    = $this->checker($dispatcher);

        self::assertFalse($checker->canImportTasks($participant, $board));
        self::assertTrue($checker->canImportIntoTask($participant, $task));
        self::assertFalse($checker->canImportIntoTask($stranger, $task));

        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_IMPORT_TASKS) {
                $event->deny();
            }
        });
        self::assertFalse($checker->canImportIntoTask($participant, $task));
        self::assertFalse($checker->canImportIntoTask($owner, $task));
    }

    private function checker(EventDispatcher $dispatcher): TaskBoardResourceAccessChecker
    {
        $repository = $this->createMock(TeamMemberRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([]);

        return new TaskBoardResourceAccessChecker($repository, new NullTaskBoardTeamMembershipResolver(), null, $dispatcher);
    }
}
