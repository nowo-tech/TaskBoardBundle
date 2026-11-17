<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;

use function json_decode;

/**
 * Beacon spec 239, audit SF-L1 (TaskBoard 2.21.0): a board move whose task a concurrent write moved after the checks (here
 * while the column transition policy is asked) writes nothing; the board shows "changed meanwhile", and the drag gets 409
 * to reload.
 */
final class ConcurrentChangeUiTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private TaskBoard $board;

    protected function setUp(): void
    {
        self::$taskBoardConfig               = ['column_transition_policy' => 'app.transition_policy'];
        ScriptedTransitionPolicy::$asked     = [];
        ScriptedTransitionPolicy::$meanwhile = null;
        parent::setUp();
        $this->board = $this->board('Flow', ['To do', 'Doing', 'Done']);
        $this->login($this->owner);
    }

    protected function tearDown(): void
    {
        ScriptedTransitionPolicy::$meanwhile = null;
        parent::tearDown();
    }

    public function testTheButtonsAndTheDragRefuseAMoveOfATaskThatChangedMeanwhile(): void
    {
        $task                                = $this->task($this->board, 'Raced');
        $done                                = $this->column('Done');
        $connection                          = $this->em->getConnection();
        ScriptedTransitionPolicy::$meanwhile = static function () use ($connection, $task, $done): void {
            $connection->executeStatement('UPDATE task_board_tasks SET column_id = ? WHERE id = ?', [$done->getId(), $task->getId()]);
        };

        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/advance', ['_token' => PredictableCsrfTokenManager::value('task_board_task_advance'), 'action' => 'next']);
        self::assertResponseRedirects();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_task_change_history WHERE task_id = ? AND change_type = ?', [$task->getId(), 'column']));

        $connection->executeStatement('UPDATE task_board_tasks SET column_id = ? WHERE id = ?', [$this->column('To do')->getId(), $task->getId()]);
        $this->em->clear();
        $this->client->xmlHttpRequest('POST', self::BASE . '/task/' . $task->getId() . '/move', ['columnId' => $this->column('Doing')->getId(), 'position' => 0, '_token' => PredictableCsrfTokenManager::value('task_board_task_move')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['refused' => 'conflict', 'message' => 'The task changed meanwhile: nothing was saved. Try again.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame($done->getId(), $connection->fetchOne('SELECT column_id FROM task_board_tasks WHERE id = ?', [$task->getId()]), 'the concurrent write stays');

        $this->client->followRedirects();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $this->board->getId());
        self::assertStringContainsString('The task changed meanwhile: nothing was saved. Try again.', $crawler->text());
    }

    /**
     * Since 2.21.1 (audit SF3-L1): the task edit form's column change is a move; a task changed meanwhile is the same flash,
     * not a 500, and nothing of the form is saved.
     */
    public function testTheEditFormRefusesAColumnChangeOfATaskThatChangedMeanwhile(): void
    {
        $task                                = $this->task($this->board, 'Raced');
        $done                                = $this->column('Done');
        $connection                          = $this->em->getConnection();
        ScriptedTransitionPolicy::$meanwhile = static function () use ($connection, $task, $done): void {
            $connection->executeStatement('UPDATE task_board_tasks SET column_id = ? WHERE id = ?', [$done->getId(), $task->getId()]);
        };

        $this->client->request('POST', self::BASE . '/task/' . $task->getId(), ['task_form' => [
            'title'    => 'Renamed',
            'columnId' => $this->column('Doing')->getId(),
            '_token'   => PredictableCsrfTokenManager::value('task_form'),
        ]]);

        self::assertResponseRedirects();
        $row = $connection->fetchAssociative('SELECT column_id, title FROM task_board_tasks WHERE id = ?', [$task->getId()]);
        self::assertSame(['column_id' => $done->getId(), 'title' => 'Raced'], $row, 'the concurrent write stays; nothing of the form is saved');
        self::assertSame(['task_changed_meanwhile'], $this->refusedReasons());

        ScriptedTransitionPolicy::$meanwhile = null;
        $this->em->clear();
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $this->board->getId());
        self::assertStringContainsString('The task changed meanwhile: nothing was saved. Try again.', $crawler->text());
    }

    /**
     * Since 2.21.1 (audit SF3-L1): a column delete that finds, once the rows are locked, one of its tasks changed by a
     * concurrent write while its copy holds unsaved changes writes nothing and flashes "changed meanwhile", not a 500.
     */
    public function testAColumnDeleteThatFindsATaskChangedMeanwhileKeepsTheColumn(): void
    {
        $doing                               = $this->column('Doing');
        $task                                = $this->task($this->board, 'Raced', 1);
        $em                                  = $this->em;
        $taskId                              = $task->getId();
        ScriptedTransitionPolicy::$meanwhile = static function () use ($em, $taskId): void {
            // A concurrent writer commits a new position; this request's copy of the task holds an unsaved change.
            $em->getConnection()->executeStatement('UPDATE task_board_tasks SET position = 7 WHERE id = ?', [$taskId]);
            $em->find(Task::class, $taskId)?->setTitle('Unsaved');
        };

        // The request reads the column's tasks as committed (its in-memory collection was built before the task).
        $this->em->clear();
        $this->client->request('POST', self::BASE . '/board/' . $this->board->getId() . '/column/' . $doing->getId() . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_column_delete')]);

        self::assertResponseRedirects();
        $connection = $this->em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM task_board_columns WHERE id = ?', [$doing->getId()]), 'the column stays');
        self::assertSame(['column_id' => $doing->getId(), 'title' => 'Raced', 'position' => 7], $this->row($taskId));
        self::assertSame(['column_changed_meanwhile'], $this->refusedReasons());

        ScriptedTransitionPolicy::$meanwhile = null;
        $this->em->clear();
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $this->board->getId());
        self::assertStringContainsString("The column's tasks changed meanwhile: nothing was saved. Try again.", $crawler->text());
    }

    /**
     * @return array{column_id: mixed, title: mixed, position: int}
     */
    private function row(string $taskId): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT column_id, title, position FROM task_board_tasks WHERE id = ?', [$taskId]);
        self::assertIsArray($row);

        return ['column_id' => $row['column_id'], 'title' => $row['title'], 'position' => (int) $row['position']];
    }

    /**
     * @return list<string> the reasons of the "write refused" warnings logged so far
     */
    private function refusedReasons(): array
    {
        $reasons = [];
        foreach ($this->logger()->records as $record) {
            if (($record['message'] ?? null) === 'TaskBoard write refused: {reason}') {
                $reasons[] = (string) ($record['context']['reason'] ?? '');
            }
        }

        return $reasons;
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
