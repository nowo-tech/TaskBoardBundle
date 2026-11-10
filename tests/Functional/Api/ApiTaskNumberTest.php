<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;

/**
 * Since 2.10.0 (spec 214): every task body carries `number`, and `GET /v2/task/{number}?custom_task_ids=true` finds a task
 * by its number within a space (`space_id`) or a list (`list_id`), ClickUp's convention for custom task ids.
 */
final class ApiTaskNumberTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    protected function setUp(): void
    {
        static::$hostConfig = static::$hostConfig !== [] ? static::$hostConfig : ['task_numbers' => ['enabled' => true]];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b', null];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->issueToken();
    }

    public function testTheBodyCarriesTheNumberAndTheNumberFindsTheTask(): void
    {
        $this->task($this->roadmap, 'First');
        $second = $this->task($this->roadmap, 'Second');
        $this->board('Other', tenantKey: 'tenant-b');
        self::assertSame(2, $second->getNumber());

        $this->get('/api/tasks/v2/task/' . $second->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['number']);

        $this->get('/api/tasks/v2/list/' . $this->roadmap->getId() . '/task');
        $numbers = array_map(static fn (array $task): mixed => $task['number'], $this->listOf('tasks'));
        sort($numbers);
        self::assertSame([1, 2], $numbers);

        foreach (['2', '%232'] as $number) {
            $this->get('/api/tasks/v2/task/' . $number . '?custom_task_ids=true&team_id=team-1&space_id=space-tenant-a&include_subtasks=true');
            self::assertResponseIsSuccessful();
            self::assertSame($second->getId(), $this->json()['id']);
            self::assertSame([], $this->json()['subtasks']);
        }

        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&list_id=' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('First', $this->json()['name']);
    }

    public function testANumberIsFoundOnlyInItsOwnSequence(): void
    {
        $this->task($this->roadmap, 'First');
        $other = $this->board('Other', tenantKey: 'tenant-b');

        foreach (['/api/tasks/v2/task/1?custom_task_ids=true&space_id=space-tenant-b', '/api/tasks/v2/task/1?custom_task_ids=true&list_id=' . $other->getId(), '/api/tasks/v2/task/9?custom_task_ids=true&space_id=space-tenant-a', '/api/tasks/v2/task/0?custom_task_ids=true&space_id=space-tenant-a', '/api/tasks/v2/task/DEV-1?custom_task_ids=true&space_id=space-tenant-a', '/api/tasks/v2/task/1?custom_task_ids=true&space_id=space-unknown'] as $path) {
            $this->get($path);
            $this->assertRefused(404, 'ACCESS_001');
        }

        $this->get('/api/tasks/v2/task/1?custom_task_ids=true');
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&space_id=space-none');
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/task/1?custom_task_ids=maybe&space_id=space-tenant-a');
        $this->assertRefused(400, 'INPUT_002');
    }

    public function testAMovedTasksOldNumberIsNotFoundThere(): void
    {
        $task = $this->task($this->roadmap, 'Leaves');
        $away = $this->board('Away', tenantKey: 'tenant-b');
        $task->changeBoard($away, $away->getColumns()->first() ?: null, 0);
        $this->em->flush();

        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&space_id=space-tenant-a');
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testATaskThatCameBackAnswersOnlyToItsNewNumber(): void
    {
        self::ensureKernelShutdown();
        static::$hostConfig = ['task_numbers' => ['enabled' => true, 'scope' => 'board']];
        $this->setUp();
        $this->task($this->roadmap, 'First');
        $task = $this->task($this->roadmap, 'Travels');
        $away = $this->board('Away', tenantKey: 'tenant-a');
        $task->changeBoard($away, $away->getColumns()->first() ?: null, 0);
        $this->em->flush();
        $task->changeBoard($this->roadmap, $this->roadmap->getColumns()->first() ?: null, 0);
        $this->em->flush();
        self::assertNotSame(2, $task->getNumber());

        // Audit JY-I1: the number it left behind in this list is 404, its new one is the task.
        $this->get('/api/tasks/v2/task/2?custom_task_ids=true&list_id=' . $this->roadmap->getId());
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/task/' . $task->getNumber() . '?custom_task_ids=true&list_id=' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSame($task->getId(), $this->json()['id']);
        // Audit JY-I5: a trailing newline is not a number.
        $this->get('/api/tasks/v2/task/1%0A?custom_task_ids=true&list_id=' . $this->roadmap->getId());
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testMyTasksStillSeesOnlyItsOwn(): void
    {
        $colleague = $this->user('colleague@example.com');
        $theirs    = new Task(board: $this->roadmap, title: 'Theirs', creator: $colleague, column: $this->roadmap->getColumns()->first() ?: null);
        $this->em->persist($theirs);
        $this->em->flush();
        $this->issueToken(TaskApiLevel::MyTasks);

        $this->get('/api/tasks/v2/task/' . $theirs->getNumber() . '?custom_task_ids=true&space_id=space-tenant-a');
        $this->assertRefused(404, 'ACCESS_001');
    }

    public function testPerBoardNumbersNeedTheList(): void
    {
        self::ensureKernelShutdown();
        static::$hostConfig = ['task_numbers' => ['enabled' => true, 'scope' => 'board']];
        $this->setUp();
        $this->task($this->roadmap, 'First');

        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&space_id=space-tenant-a');
        $this->assertRefused(400, 'INPUT_002');
        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&list_id=' . $this->roadmap->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['number']);
    }

    public function testWithoutTaskNumbersTheNumberIsNullAndNothingIsFound(): void
    {
        self::ensureKernelShutdown();
        static::$hostConfig = ['calendar' => ['enabled' => false]];
        $this->setUp();
        $task = $this->task($this->roadmap, 'First');

        $this->get('/api/tasks/v2/task/' . $task->getId());
        self::assertNull($this->json()['number']);
        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&space_id=space-tenant-a');
        $this->assertRefused(404, 'ACCESS_001');
        $this->get('/api/tasks/v2/task/1?custom_task_ids=true&list_id=' . $this->roadmap->getId());
        $this->assertRefused(404, 'ACCESS_001');
    }
}
