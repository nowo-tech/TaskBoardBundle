<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Hook\ManualTaskApiTimeEntries;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

/**
 * Spec 213 S5: the bundle's own manual time as the API's time store, for a host without one.
 */
final class ApiManualTimeTest extends ApiTestCase
{
    protected function setUp(): void
    {
        static::$api = ['host_decides_access' => true, 'hooks' => [
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
}
