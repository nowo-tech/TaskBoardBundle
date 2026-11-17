<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
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
