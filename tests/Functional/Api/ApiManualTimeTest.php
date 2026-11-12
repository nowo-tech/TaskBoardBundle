<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Hook\ManualTaskApiTimeEntries;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiAuditRecorder;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTimeBillable;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 213 S5: the bundle's own manual time as the API's time store, for a host without one.
 */
final class ApiManualTimeTest extends ApiTestCase
{
    protected function setUp(): void
    {
        self::$api = ['host_decides_access' => true, 'hooks' => [
            'authenticator' => 'app.api_host',
            'rate_limiter'  => 'app.api_host',
            'plan_gate'     => 'app.api_host',
            'workspace'     => 'app.api_host',
            'users'         => 'app.api_host',
            'time_entries'  => ManualTaskApiTimeEntries::class,
        ]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
    }

    public function testLogAndListOwnManualTimeOnTasksWithinReach(): void
    {
        $board     = $this->board(tenantKey: 'tenant-a');
        $task      = $this->task($board, 'Timed');
        $colleague = $this->user('colleague@example.com');
        $theirs    = new Task(board: $board, title: 'Theirs', creator: $colleague);
        $this->em->persist($theirs);
        $theirEntry = new TaskTimeEntry($task, $colleague, 30, new DateTimeImmutable('2026-11-01'));
        $task->addTimeEntry($theirEntry);
        $this->em->persist($theirEntry);
        $mineElsewhere = new TaskTimeEntry($theirs, $this->owner, 15, new DateTimeImmutable('2026-11-01'));
        $theirs->addTimeEntry($mineElsewhere);
        $this->em->persist($mineElsewhere);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $task->getId(), 'start' => 1790000000000, 'duration' => 5400000, 'description' => 'Deep work']);
        self::assertResponseIsSuccessful();
        self::assertSame('5400000', $this->json()['data']['duration']);
        $this->em->clear();
        self::assertSame(120, $this->em->find(Task::class, $task->getId())?->getLoggedMinutes(), "the task page's time grows");

        $this->get('/api/tasks/v2/team/team-1/time_entries?start_date=1789000000000&end_date=1791000000000');
        self::assertSame(['Deep work'], array_column($this->listOf('data'), 'description'), "only the person's own, on tasks the token opens");
        $this->get('/api/tasks/v2/team/team-1/time_entries?task_id=' . $task->getId());
        self::assertCount(1, $this->listOf('data'));

        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['start' => 1790000000000, 'duration' => 60000]);
        $this->assertRefused(400, 'INPUT_002');
    }

    /** Since 2.12.0 (Beacon D-20261111-12): `billable` on a log, and `PUT` for the token's own entries. */
    public function testBillableIsLoggedReadAndChangedOnOwnEntries(): void
    {
        $board     = $this->board(tenantKey: 'tenant-a');
        $task      = $this->task($board, 'Timed');
        $colleague = $this->user('colleague@example.com');
        $theirs    = new TaskTimeEntry($task, $colleague, 30, new DateTimeImmutable('2026-11-01'));
        $task->addTimeEntry($theirs);
        $this->em->persist($theirs);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);
        ScriptedTimeBillable::$default = true;

        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $task->getId(), 'start' => 1790000000000, 'duration' => 600000]);
        self::assertTrue($this->json()['data']['billable'], 'the host default');
        $this->send('POST', '/api/tasks/v2/team/team-1/time_entries', ['tid' => $task->getId(), 'start' => 1790001000000, 'duration' => 600000, 'billable' => false]);
        $id = $this->json()['data']['id'];
        self::assertFalse($this->json()['data']['billable']);
        self::assertArrayNotHasKey('warnings', $this->json());

        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/' . $id, ['billable' => true, 'description' => 'ignored']);
        self::assertResponseIsSuccessful();
        self::assertSame([$id, true], [$this->json()['data']['id'], $this->json()['data']['billable']]);
        self::assertSame([['field' => 'description', 'reason' => 'not_supported']], $this->json()['warnings']);
        // Audit MV-B-I2: the audit row names the board.
        $audit = self::getContainer()->get('app.api_audit');
        self::assertInstanceOf(ApiAuditRecorder::class, $audit);
        $row = end($audit->events);
        self::assertNotFalse($row);
        self::assertSame(['time.billable', $board->getId(), 'tenant-a', $task->getId(), ['billable']], [$row->operation, $row->boardId, $row->tenantKey, $row->taskId, $row->changedFields]);
        $this->em->clear();
        self::assertTrue($this->em->find(TaskTimeEntry::class, $id)?->getBillable());
        // The task's history rows have no guaranteed order on every database: look for the billable one.
        $rows = [];
        foreach ($this->em->find(Task::class, $task->getId())?->getChangeHistory() ?? [] as $row) {
            if ($row->getChangeType() === TaskChangeType::TimeBillable) {
                $rows[] = [$row->getOldValue(), $row->getNewValue()];
            }
        }
        self::assertSame([['false', 'true']], $rows);
        $this->get('/api/tasks/v2/team/team-1/time_entries?task_id=' . $task->getId());
        self::assertEqualsCanonicalizing([true, true], array_column($this->listOf('data'), 'billable'));

        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/' . $id, ['description' => 'no flag']);
        $this->assertRefused(400, 'INPUT_002');
        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/' . $theirs->getId(), ['billable' => true]);
        $this->assertRefused(404, 'ACCESS_001');
        $this->send('PUT', '/api/tasks/v2/team/team-9/time_entries/' . $id, ['billable' => true]);
        $this->assertRefused(404, 'ACCESS_001');

        // A locked period, then an edit right the host withdraws.
        ScriptedTimeBillable::$lockedDays = ['2026-09-21'];
        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/' . $id, ['billable' => false]);
        $this->assertRefused(409, 'TIME_004');
        ScriptedTimeBillable::$lockedDays = [];
        $dispatcher                       = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->grant();
            $event->markReadOnly();
        });
        $this->send('PUT', '/api/tasks/v2/team/team-1/time_entries/' . $id, ['billable' => false]);
        $this->assertRefused(403, 'ACCESS_002');
        ScriptedTimeBillable::reset();
    }

    /** Since 2.12.0: deleting time stays refused (spec 213). */
    public function testTimeIsStillNeverDeleted(): void
    {
        $this->issueToken();
        $this->send('DELETE', '/api/tasks/v2/team/team-1/time_entries/any');
        $this->assertRefused(403, 'TIME_002');
    }
}
