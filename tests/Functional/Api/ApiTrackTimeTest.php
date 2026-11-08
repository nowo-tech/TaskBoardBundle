<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.4.0 (spec 213, D-20261108-13): `POST /v2/team/{team_id}/time_entries` with a `tid` asks the host's time-tracking
 * decision ({@see TaskAccessCheckEvent::ACTION_TRACK_TIME}) instead of the task's edit right, which stays the fallback.
 */
final class ApiTrackTimeTest extends ApiTestCase
{
    private const PATH = '/api/tasks/v2/team/team-1/time_entries';

    private TaskBoard $roadmap;

    private Task $own;

    private Task $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->own            = $this->task($this->roadmap, 'Own');
        $this->theirs         = new Task(board: $this->roadmap, title: 'Theirs', creator: $this->user('colleague@example.com'));
        $this->em->persist($this->theirs);
        $this->em->flush();
        $this->issueToken();
    }

    public function testWithoutAHostAnswerTheEditRightDecides(): void
    {
        $this->logOn($this->own);
        self::assertResponseIsSuccessful('an editor logs time, as before');

        $this->readOnlyBoard();
        $this->logOn($this->own);
        $this->assertRefused(403, 'ACCESS_002');
        self::assertCount(1, ApiTestHost::$logged);
    }

    public function testTheHostAllowsTimeWithoutTheEditRight(): void
    {
        $this->readOnlyBoard();
        $this->answerTrackTime(true);

        $this->logOn($this->own);
        self::assertResponseIsSuccessful('a project member who may track time logs it without editing the task');
        self::assertSame($this->own->getId(), ApiTestHost::$logged[0][0]);

        // Only the time log: editing the task stays refused.
        $this->send('PUT', '/api/tasks/v2/task/' . $this->own->getId(), ['name' => 'Renamed']);
        $this->assertRefused(403, 'ACCESS_002');
    }

    public function testTheHostDeniesTimeToAnEditor(): void
    {
        $this->answerTrackTime(false);

        $this->logOn($this->own);
        $this->assertRefused(403, 'ACCESS_002');
        self::assertSame([], ApiTestHost::$logged);

        // Time without a task is the host's own call, as before.
        $this->send('POST', self::PATH, ['start' => 1790000000000, 'duration' => 60000]);
        self::assertResponseIsSuccessful();
    }

    public function testTheTokenLevelStillApplies(): void
    {
        $this->readOnlyBoard();
        $this->answerTrackTime(true);

        $this->issueToken(TaskApiLevel::MyTasks);
        $this->logOn($this->theirs);
        $this->assertRefused(404, 'ACCESS_001');
        $this->logOn($this->own);
        self::assertResponseIsSuccessful('My tasks logs time on its own task');

        $this->issueToken(TaskApiLevel::Read);
        $this->logOn($this->own);
        $this->assertRefused(403, 'TOKEN_001');

        foreach ([TaskApiLevel::Tasks, TaskApiLevel::Full] as $level) {
            $this->issueToken($level);
            $this->logOn($this->theirs);
            self::assertResponseIsSuccessful($level->value . ' logs time on any task within reach');
        }
        self::assertCount(3, ApiTestHost::$logged);
    }

    public function testAGrantNeverBringsATaskIntoReach(): void
    {
        // Audit II-I5 (a): another tenant, outside where, a board the person cannot view: 404 before the decision.
        $foreign       = $this->task($this->board('Elsewhere', tenantKey: 'tenant-b'), 'Foreign');
        $hidden        = $this->task($this->board('Hidden', tenantKey: 'tenant-a'), 'Hidden');
        $hiddenBoardId = $hidden->getBoard()->getId();
        $this->answerTrackTime(true);
        $this->logOn($foreign);
        $this->assertRefused(404, 'ACCESS_001');

        $this->issueToken(where: TaskApiWhere::limitedTo([], [$foreign->getBoard()->getId()]));
        $this->logOn($this->own);
        $this->assertRefused(404, 'ACCESS_001');

        $this->issueToken();
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($hiddenBoardId): void {
            if ($event->getBoard()->getId() === $hiddenBoardId) {
                $event->deny();
            }
        });
        $this->logOn($hidden);
        $this->assertRefused(404, 'ACCESS_001');
        self::assertSame([], ApiTestHost::$logged);
    }

    public function testAGrantMarkedReadOnlyFallsBack(): void
    {
        // Audit II-I5 (b): the host grants and marks read-only: the edit right decides, and the board is read-only.
        $this->readOnlyBoard();
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->grant();
                $event->markReadOnly();
            }
        });

        $this->logOn($this->own);
        $this->assertRefused(403, 'ACCESS_002');
    }

    public function testADenyWinsOverALaterGrant(): void
    {
        // Audit II-I5 (c), II-L1: the host denies at priority 10, an action-blind listener grants at priority -10.
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $event->deny();
            }
        }, 10);
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->grant();
        }, -10);

        $this->logOn($this->own);
        $this->assertRefused(403, 'ACCESS_002');
        $this->send('PUT', '/api/tasks/v2/task/' . $this->own->getId(), ['name' => 'Still editable']);
        self::assertResponseIsSuccessful('editing follows the view action, where the last call still wins');
        self::assertSame([], ApiTestHost::$logged);
    }

    public function testDeletingTimeStaysRefused(): void
    {
        $this->answerTrackTime(true);
        $this->logOn($this->own);
        self::assertResponseIsSuccessful();

        foreach (TaskApiLevel::cases() as $level) {
            $this->issueToken($level);
            $this->send('DELETE', self::PATH . '/entry-1');
            $this->assertRefused(403, 'TIME_002');
        }
    }

    private function logOn(Task $task): void
    {
        $this->send('POST', self::PATH, ['tid' => $task->getId(), 'start' => 1790000000000, 'duration' => 60000]);
    }

    /** The person sees the board read-only: view access, no edit right. */
    private function readOnlyBoard(): void
    {
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->grant();
                $event->markReadOnly();
            }
        });
    }

    private function answerTrackTime(bool $allow): void
    {
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event) use ($allow): void {
            if ($event->getAction() === TaskAccessCheckEvent::ACTION_TRACK_TIME) {
                $allow ? $event->grant() : $event->deny();
            }
        });
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
