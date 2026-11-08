<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Security;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Since 2.4.0 (spec 213, D-20261108-13): logging time is its own TASK_ACCESS_CHECK decision, `track_time`, answered by
 * the host; with no answer the edit right decides, as before.
 */
final class TaskTrackTimeAccessTest extends TestCase
{
    private TestUser $owner;

    private TestUser $reader;

    private TestUser $stranger;

    private TaskBoard $board;

    private Task $task;

    protected function setUp(): void
    {
        $this->owner    = new TestUser('1', 'owner@example.com');
        $this->reader   = new TestUser('2', 'reader@example.com');
        $this->stranger = new TestUser('3', 'stranger@example.com');
        $this->board    = new TaskBoard('Board', 'board', $this->owner);
        $this->task     = new Task(board: $this->board, title: 'Task', creator: $this->owner);
    }

    public function testTheEventCarriesItsAction(): void
    {
        self::assertSame('view', (new TaskAccessCheckEvent($this->owner, $this->task))->getAction());
        self::assertSame('track_time', TaskAccessCheckEvent::ACTION_TRACK_TIME);
        self::assertSame(TaskAccessCheckEvent::ACTION_TRACK_TIME, (new TaskAccessCheckEvent($this->owner, $this->task, TaskAccessCheckEvent::ACTION_TRACK_TIME))->getAction());
    }

    public function testWithoutAHostAnswerTheEditRightDecides(): void
    {
        $dispatcher = $this->readOnlyReader();
        $actions    = [];
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use (&$actions): void {
            $actions[] = $event->getAction();
        });
        $checker = $this->checker($dispatcher);

        self::assertTrue($checker->canTrackTime($this->owner, $this->task));
        self::assertTrue($checker->canViewTask($this->reader, $this->task));
        self::assertFalse($checker->canEditTask($this->reader, $this->task));
        self::assertFalse($checker->canTrackTime($this->reader, $this->task));
        self::assertFalse($checker->canTrackTime($this->stranger, $this->task));
        self::assertContains(TaskAccessCheckEvent::ACTION_TRACK_TIME, $actions);

        // Without any dispatcher at all, the same.
        $plain = $this->checker(null);
        self::assertTrue($plain->canTrackTime($this->owner, $this->task));
        self::assertFalse($plain->canTrackTime($this->stranger, $this->task));
    }

    public function testTheHostAllowsTimeWithoutTheEditRight(): void
    {
        $dispatcher = $this->readOnlyReader();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->grant();
            }
        });
        $checker = $this->checker($dispatcher);

        self::assertFalse($checker->canEditTask($this->reader, $this->task));
        self::assertTrue($checker->canTrackTime($this->reader, $this->task));
        // Viewing stays required: the grant does not open a task the person cannot see.
        self::assertFalse($checker->canTrackTime($this->stranger, $this->task));
    }

    public function testTheHostDeniesTimeToAnEditor(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->deny();
            }
        });
        $checker = $this->checker($dispatcher);

        self::assertTrue($checker->canEditTask($this->owner, $this->task));
        self::assertFalse($checker->canTrackTime($this->owner, $this->task));
    }

    public function testAListenerBlindToTheActionKeepsTodaysBehaviour(): void
    {
        // Grants every task but marks it read-only, whatever the action: as before 2.4.0, no time without the edit right.
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->grant();
            $event->markReadOnly();
        });
        $checker = $this->checker($dispatcher);
        self::assertTrue($checker->canViewTask($this->stranger, $this->task));
        self::assertFalse($checker->canTrackTime($this->stranger, $this->task));

        // Grants every task outright: time follows the edit right, which is granted too.
        $granting = new EventDispatcher();
        $granting->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->grant();
        });
        $checker = $this->checker($granting);
        self::assertTrue($checker->canEditTask($this->stranger, $this->task));
        self::assertTrue($checker->canTrackTime($this->stranger, $this->task));
    }

    public function testATrackTimeDenySticksAgainstALowerPriorityGrant(): void
    {
        // Audit II-L1: an action-aware deny at priority 10, then an action-blind grant at priority 0.
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->deny();
            }
        }, 10);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->grant();
        }, 0);
        $checker = $this->checker($dispatcher);

        self::assertTrue($checker->canEditTask($this->owner, $this->task));
        self::assertFalse($checker->canTrackTime($this->owner, $this->task));
    }

    public function testOnlyTheTrackTimeDenySticks(): void
    {
        $track = new TaskAccessCheckEvent($this->owner, $this->task, TaskAccessCheckEvent::ACTION_TRACK_TIME);
        $track->deny();
        $track->grant();
        self::assertTrue($track->isDenied());
        self::assertFalse($track->isGranted());

        // Viewing keeps "the last call wins", as before 2.4.0.
        $view = new TaskAccessCheckEvent($this->owner, $this->task);
        $view->deny();
        $view->grant();
        self::assertTrue($view->isGranted());
        self::assertFalse($view->isDenied());
    }

    public function testAGrantMarkedReadOnlyFallsBackToTheEditRight(): void
    {
        $dispatcher = $this->readOnlyReader();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->grant();
                $event->markReadOnly();
            }
        });
        $checker = $this->checker($dispatcher);

        self::assertFalse($checker->canTrackTime($this->reader, $this->task));
        self::assertTrue($checker->canTrackTime($this->owner, $this->task), 'the editor keeps the edit right');
    }

    public function testAReadOnlyMarkFromAnotherListenerCancelsTheGrant(): void
    {
        // Audit II-I2: the host grants track_time at priority 10; an action-blind listener marks every task read-only later.
        $dispatcher = $this->readOnlyReader();
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->grant();
            }
        }, 10);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->markReadOnly();
        }, 0);
        $checker = $this->checker($dispatcher);

        self::assertFalse($checker->canTrackTime($this->reader, $this->task));
    }

    /** The reader sees the board read-only: view access without the edit right. */
    private function readOnlyReader(): EventDispatcher
    {
        $reader     = $this->reader;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($reader): void {
            if ($event->getSubject() === $reader && $event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->grant();
                $event->markReadOnly();
            }
        });

        return $dispatcher;
    }

    private function checker(?EventDispatcher $dispatcher): TaskBoardResourceAccessChecker
    {
        $repository = $this->createMock(TeamMemberRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([]);

        return new TaskBoardResourceAccessChecker($repository, new NullTaskBoardTeamMembershipResolver(), null, $dispatcher);
    }
}
