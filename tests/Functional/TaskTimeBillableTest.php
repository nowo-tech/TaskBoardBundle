<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskTimeBillableChangedEvent;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableService;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTimeBillable;

use function array_values;
use function in_array;

/**
 * Since 2.12.0 (Beacon D-20261111-12): the Billable switch of *Log time* (host default), the per-entry switch for its
 * author or a board manager, the period lock and the audit (history row and event).
 */
final class TaskTimeBillableTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board/';

    /** @var list<TaskTimeBillableChangedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        ScriptedTimeBillable::reset();
        $this->events = [];
        $dispatcher   = self::getContainer()->get('event_dispatcher');
        self::assertIsObject($dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_TIME_BILLABLE_CHANGED, function (TaskTimeBillableChangedEvent $event): void {
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        ScriptedTimeBillable::reset();
        parent::tearDown();
    }

    public function testLogTimeTakesTheSwitchPresetToTheHostDefault(): void
    {
        $task                          = $this->task($this->board(), 'Work');
        $taskId                        = $task->getId();
        ScriptedTimeBillable::$default = true;
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertResponseIsSuccessful();
        self::assertSame('checked', $crawler->filter('input[name="task_time_entry[billable]"]')->attr('checked'), 'preset to the host default');

        $this->logTime($taskId, 30, true);
        $this->logTime($taskId, 20, false);
        ScriptedTimeBillable::$default = null;
        $this->logTime($taskId, 10, false);
        $this->logTime($taskId, 5, true);

        $this->em->clear();
        $minutes = [];
        foreach ($this->em->find(Task::class, $taskId)?->getTimeEntries() ?? [] as $entry) {
            $minutes[$entry->getMinutes()] = $entry->getBillable();
        }
        self::assertSame([30 => true, 20 => false, 10 => null, 5 => true], $minutes, 'switched off with no host default: not set, as before');
    }

    public function testOnlyItsAuthorOrABoardManagerSwitchesAnEntryOutsideALockedPeriod(): void
    {
        $managers   = [$this->owner->getEmail()];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertIsObject($dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($managers): void {
            $subject = $event->getSubject();
            if (!$subject instanceof AppUser) {
                return;
            }
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE && !in_array($subject->getEmail(), $managers, true)) {
                $event->deny();

                return;
            }
            $event->grant();
        });
        $author = $this->user('author@example.com');
        $editor = $this->user('editor@example.com');
        $task   = $this->task($this->board(), 'Work');
        foreach ([$author, $editor] as $member) {
            $task->addMember(new TaskMember($task, $member, TaskMemberRole::Assignee));
        }
        $this->em->flush();
        $entry  = $this->timeLogger()->log($task, $author, 10, new DateTimeImmutable('2026-11-02'));
        $locked = $this->timeLogger()->log($task, $author, 20, new DateTimeImmutable('2026-11-03'));
        $taskId = $task->getId();

        // Another editor: no switch, and refused.
        $this->login($editor);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertCount(0, $crawler->filter('.nowo-task-time-entries__billable-toggle'));
        $this->client->catchExceptions(true);
        $this->switch($taskId, $entry->getId(), '1');
        self::assertResponseStatusCodeSame(403);

        // The author marks it billable; a manager marks it not billable.
        $this->login($author);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        self::assertCount(2, $crawler->filter('.nowo-task-time-entries__billable-toggle'));
        $this->switch($taskId, $entry->getId(), '1');
        self::assertResponseRedirects();
        $this->login($this->owner);
        $this->switch($taskId, $entry->getId(), '0');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertFalse($this->em->find(TaskTimeEntry::class, $entry->getId())?->getBillable());

        // Audit: two history rows (who, old, new, the day) and two events.
        $rows = array_values(array_filter($this->em->find(Task::class, $taskId)?->getChangeHistory()->toArray() ?? [], static fn (TaskChangeHistory $row): bool => $row->getChangeType() === TaskChangeType::TimeBillable));
        self::assertSame([[null, 'true', '2026-11-02', 'author@example.com'], ['true', 'false', '2026-11-02', 'owner@example.com']], array_map(static fn (TaskChangeHistory $row): array => [$row->getOldValue(), $row->getNewValue(), $row->getContext(), $row->getUser()?->getEmail()], $rows));
        self::assertSame([[null, true], [true, false]], array_map(static fn (TaskTimeBillableChangedEvent $e): array => [$e->oldBillable, $e->newBillable], $this->events));
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $taskId);
        $page    = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('changed whether time is billable', $page);
        self::assertStringContainsString('data-billable="0"', $crawler->html());

        // A locked period: refused with a message, nothing written.
        ScriptedTimeBillable::$lockedDays = ['2026-11-03'];
        $this->switch($taskId, $locked->getId(), '1');
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->find(TaskTimeEntry::class, $locked->getId())?->getBillable());
        self::assertCount(2, $this->events);

        // Bad input and unknown entries.
        $this->switch($taskId, $entry->getId(), 'yes');
        self::assertResponseStatusCodeSame(400);
        $this->switch($taskId, 'missing', '1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheServiceIsPublicForHosts(): void
    {
        self::assertInstanceOf(TaskTimeBillableService::class, self::getContainer()->get(TaskTimeBillableService::class));
    }

    private function logTime(string $taskId, int $minutes, bool $billable): void
    {
        $fields = ['minutes' => (string) $minutes, 'loggedAt' => '2026-11-04', 'note' => '', '_token' => PredictableCsrfTokenManager::value('task_time_entry')];
        if ($billable) {
            $fields['billable'] = '1';
        }
        $this->client->request('POST', self::BASE . 'task/' . $taskId . '/time', ['task_time_entry' => $fields]);
        self::assertResponseRedirects();
    }

    private function switch(string $taskId, string $entryId, string $value): void
    {
        $this->client->request('POST', self::BASE . 'task/' . $taskId . '/time/' . $entryId . '/billable', ['_token' => PredictableCsrfTokenManager::value('task_board_task_time_billable'), 'billable' => $value]);
    }

    private function timeLogger(): TaskTimeLogger
    {
        $logger = self::getContainer()->get(TaskTimeLogger::class);
        self::assertInstanceOf(TaskTimeLogger::class, $logger);

        return $logger;
    }
}
