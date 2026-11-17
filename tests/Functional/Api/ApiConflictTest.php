<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\RecordingTransitionPolicy;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransition;
use Nowo\TaskBoardBundle\ValueObject\ColumnTransitionDecision;

use function array_intersect_key;

/**
 * Beacon spec 239, audits SF-L1 / SF-2 (TaskBoard 2.21.0): a status change whose task a concurrent write changed after the
 * checks (here while the column transition policy is asked) writes nothing and answers 409 `TASK_409` with the task's
 * current revision, as a stale base revision does: read it again. In a bulk update the item carries it in `BULK_001`.
 */
final class ApiConflictTest extends ApiTestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        self::$hostConfig = ['column_transition_policy' => 'app.transition_policy'];
        RecordingTransitionPolicy::reset();
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->board          = $this->board('Roadmap', ['To do', 'Doing', 'Done'], tenantKey: 'tenant-a');
        $this->issueToken();
    }

    protected function tearDown(): void
    {
        RecordingTransitionPolicy::reset();
        parent::tearDown();
    }

    public function testAStatusChangeOfATaskMovedMeanwhileIsTask409(): void
    {
        $task = $this->task($this->board, 'Raced');
        $this->meanwhile($task);

        $this->send('PUT', '/api/tasks/v2/task/' . $task->getId(), ['status' => 'Doing']);

        $this->assertTask409();
        $this->assertDoneAndUntouched($task);
    }

    public function testABulkItemOfATaskMovedMeanwhileIsTask409InItsPlace(): void
    {
        $task = $this->task($this->board, 'Raced');
        $this->meanwhile($task);

        $this->send('POST', '/api/tasks/v2/task/bulk', ['tasks' => [['id' => $task->getId(), 'status' => 'Doing']]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('BULK_001', $this->json()['ECODE'] ?? null);
        $failure = $this->json()['failures'][0] ?? [];
        self::assertSame(['TASK_409', $task->getId()], [$failure['ECODE'] ?? null, $failure['id'] ?? null]);
        self::assertArrayHasKey('current_revision', $failure);
        // Here the "concurrent" write ran on the test's connection, inside the bulk update's transaction: the rollback took
        // it too. Nothing of the bulk update is left either.
        $this->assertDoneAndUntouched($task, 'To do');
    }

    public function testAChecklistItemMovedMeanwhileIsTask409(): void
    {
        $task = $this->task($this->board, 'Release');
        $this->send('POST', '/api/tasks/v2/task/' . $task->getId() . '/checklist', ['name' => 'Steps']);
        $base = '/api/tasks/v2/checklist/' . $this->json()['checklist']['id'];
        $this->send('POST', $base . '/checklist_item', ['name' => 'Write']);
        $itemId = $this->json()['checklist']['items'][0]['id'];
        $this->em->clear();
        $item = $this->em->find(Task::class, $itemId);
        self::assertInstanceOf(Task::class, $item);
        $this->meanwhile($item, 'Doing');

        $this->send('PUT', $base . '/checklist_item/' . $itemId, ['resolved' => true]);

        $this->assertTask409();
        self::assertSame($this->column('Doing')->getId(), $this->em->getConnection()->fetchOne('SELECT column_id FROM task_board_tasks WHERE id = ?', [$itemId]));
    }

    /**
     * While the policy is asked, a concurrent write moves the task to `$to` (committed before this write locks the rows).
     */
    private function meanwhile(Task $task, string $to = 'Done'): void
    {
        $connection                         = $this->em->getConnection();
        $columnId                           = $this->column($to)->getId();
        $taskId                             = $task->getId();
        RecordingTransitionPolicy::$decider = static function (ColumnTransition $transition) use ($connection, $columnId, $taskId): ?ColumnTransitionDecision {
            $connection->executeStatement('UPDATE task_board_tasks SET column_id = ? WHERE id = ?', [$columnId, $taskId]);

            return ColumnTransitionDecision::allow();
        };
    }

    private function assertTask409(): void
    {
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['err' => 'The task changed: read it again', 'ECODE' => 'TASK_409'], array_intersect_key($this->json(), ['err' => 1, 'ECODE' => 1]));
        self::assertIsInt($this->json()['current_revision'] ?? null);
    }

    private function assertDoneAndUntouched(Task $task, string $column = 'Done'): void
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT column_id, revision FROM task_board_tasks WHERE id = ?', [$task->getId()]);
        self::assertIsArray($row);
        self::assertSame($this->column($column)->getId(), $row['column_id'], 'the concurrent write stays; nothing else was written');
        self::assertSame(1, (int) $row['revision']);
    }

    private function column(string $name): BoardColumn
    {
        foreach ($this->board->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }
        self::fail('No column ' . $name);
    }
}
