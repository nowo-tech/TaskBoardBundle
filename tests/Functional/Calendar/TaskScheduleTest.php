<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskUpdatedEvent;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function json_decode;

/**
 * Spec 214, block D: the write route: dates, history, events, permissions and CSRF.
 */
final class TaskScheduleTest extends CalendarTestCase
{
    public function testItMovesTheDatesWithHistoryAndTheEvent(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);
        /** @var list<TaskUpdatedEvent> $events */
        $events = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $answer = $this->schedule($task, '2026-11-13', '2026-11-15');

        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Moved to 2026-11-15', 'start' => '2026-11-13', 'due' => '2026-11-15'], $answer);
        $this->em->clear();
        $stored = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $stored);
        self::assertSame('2026-11-13', $stored->getStartAt()?->format('Y-m-d'));
        self::assertSame('2026-11-15', $stored->getDueAt()?->format('Y-m-d'));
        self::assertCount(1, $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task->getId(), 'changeType' => TaskChangeType::StartDate]));
        self::assertCount(1, $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $task->getId(), 'changeType' => TaskChangeType::DueDate]));
        self::assertCount(1, $events);
        self::assertSame(['startAt', 'dueAt'], $events[0]->getChangedFields());
    }

    public function testASingleChangeReportsOnlyThatField(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);
        $events = [];
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_UPDATED, static function (TaskUpdatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $this->schedule($task, '2026-11-10', '2026-11-14');
        self::assertSame(['dueAt'], $events[0]->getChangedFields());

        // The same dates again: no write, no event.
        $this->schedule($task, '2026-11-10', '2026-11-14');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $events);
    }

    /** Spec: "a task imported with a time keeps its time of day when moved". */
    public function testATimeOfDayIsKept(): void
    {
        $task = $this->dated($this->board(), 'Task', null, '2026-11-12 15:30:00');
        $this->login($this->owner);

        $this->schedule($task, '', '2026-11-20');

        $this->em->clear();
        $stored = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $stored);
        self::assertSame('2026-11-20 15:30', $stored->getDueAt()?->format('Y-m-d H:i'));
        self::assertNull($stored->getStartAt());
    }

    public function testAnEmptyDateClearsItAndUndoPutsItBack(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        self::assertSame('2026-11-12', $this->schedule($task, '', '2026-11-12')['due']);
        self::assertNull($this->em->find(Task::class, $task->getId())?->getStartAt());
        self::assertSame('Dates removed', $this->schedule($task, '', '')['message']);
        self::assertNull($this->em->find(Task::class, $task->getId())?->getDueAt());
        self::assertSame('2026-11-10', $this->schedule($task, '2026-11-10', '2026-11-12')['start']);
    }

    public function testStartAfterDueIsRefusedAndNothingChanges(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        $answer = $this->schedule($task, '2026-11-20', '2026-11-12');

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('message', $answer);
        self::assertArrayNotHasKey('due', $answer);
        $this->em->clear();
        self::assertSame('2026-11-10', $this->em->find(Task::class, $task->getId())?->getStartAt()?->format('Y-m-d'));
    }

    public function testAnImpossibleDateIsRefused(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        foreach (['2026-02-31', 'tomorrow', '2026-11-10 12:00', '0000-01-01', '0001-01-01', '1969-12-31', '2201-01-01', '9999-12-31'] as $day) {
            $this->schedule($task, '2026-11-10', $day);
            self::assertResponseStatusCodeSame(422, $day);
        }

        // A bad start must not be reported as a bad due date.
        self::assertSame('The dates could not be changed.', $this->schedule($task, '1969-12-31', '2026-11-12')['message']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheYearsTheCalendarShowsAreAccepted(): void
    {
        $task = $this->dated($this->board(), 'Task', null, '2026-11-12');
        $this->login($this->owner);

        foreach (['1970-01-01', '2200-12-31'] as $day) {
            $this->schedule($task, '', $day);
            self::assertResponseIsSuccessful($day);
        }
    }

    public function testAMissingFieldNeverClearsADate(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        $this->client->request('POST', $this->url($task), ['start' => '2026-11-11', '_token' => PredictableCsrfTokenManager::value('task_board_task_schedule')]);

        self::assertResponseStatusCodeSame(400);
        $this->em->clear();
        self::assertSame('2026-11-12', $this->em->find(Task::class, $task->getId())?->getDueAt()?->format('Y-m-d'));
    }

    public function testAWrongTokenIsRefused(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        $this->client->request('POST', $this->url($task), ['start' => '2026-11-11', 'due' => '2026-11-12', '_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame('2026-11-10', $this->em->find(Task::class, $task->getId())?->getStartAt()?->format('Y-m-d'));
    }

    public function testAGetIsNotAllowed(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        $this->client->request('GET', $this->url($task));

        self::assertResponseStatusCodeSame(405);
    }

    /** FR-028: read-only 403, out of reach 404. */
    public function testReadOnlyAnswers403AndOutOfReach404(): void
    {
        $task = $this->dated($this->board(), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);
        $listener = static function (TaskAccessCheckEvent $event): void {
            $event->grant();
            $event->markReadOnly();
        };
        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, $listener);
        $this->schedule($task, '2026-11-11', '2026-11-12');
        self::assertResponseStatusCodeSame(403);
        $this->dispatcher()->removeListener(TaskBoardEvents::TASK_ACCESS_CHECK, $listener);

        $this->dispatcher()->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->deny();
        });
        $this->schedule($task, '2026-11-11', '2026-11-12');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/tools/task-board/task/unknown/schedule', ['start' => '', 'due' => '', '_token' => PredictableCsrfTokenManager::value('task_board_task_schedule')]);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertSame('2026-11-10', $this->em->find(Task::class, $task->getId())?->getStartAt()?->format('Y-m-d'));
    }

    public function testATaskOfAnotherTenantIsOutOfReach(): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = self::CONFIG + ['tenancy' => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}/tasks', 'name_prefix' => 't_']]]];
        $this->setUp();
        $task = $this->dated($this->board(tenantKey: 'other'), 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        $this->client->request('POST', '/t/acme/tasks/task/' . $task->getId() . '/schedule', ['start' => '', 'due' => '', '_token' => PredictableCsrfTokenManager::value('task_board_task_schedule')]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutFetchItRedirectsWithAFlashAndAnExternalTargetIsIgnored(): void
    {
        $board = $this->board();
        $task  = $this->dated($board, 'Task', '2026-11-10', '2026-11-12');
        $this->login($this->owner);

        $this->client->request('POST', $this->url($task), ['start' => '2026-11-11', 'due' => '2026-11-12', '_token' => PredictableCsrfTokenManager::value('task_board_task_schedule'), '_target_path' => 'https://evil.example/']);

        self::assertResponseRedirects($this->calendarUrl($board));
    }

    /** @return array<string, mixed> */
    private function schedule(Task $task, string $start, string $due): array
    {
        $this->client->xmlHttpRequest('POST', $this->url($task), ['start' => $start, 'due' => $due, '_token' => PredictableCsrfTokenManager::value('task_board_task_schedule')]);

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function url(Task $task): string
    {
        return '/tools/task-board/task/' . $task->getId() . '/schedule';
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
