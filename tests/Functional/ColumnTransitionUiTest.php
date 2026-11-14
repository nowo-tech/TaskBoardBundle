<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;

use function json_decode;

/**
 * Spec 234 (TaskBoard 2.16.0): the column transition policy is asked for every move of the board UI (channel `web`);
 * a refusal leaves the task where it was and shows the policy's message; the humans-only toggle of the column dialogs.
 */
final class ColumnTransitionUiTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private TaskBoard $board;

    protected function setUp(): void
    {
        self::$taskBoardConfig             = ['column_transition_policy' => 'app.transition_policy'];
        ScriptedTransitionPolicy::$asked   = [];
        ScriptedTransitionPolicy::$message = 'A blocked task needs a reason';
        parent::setUp();
        $this->board = $this->board('Flow', ['To do', 'Blocked', 'Done']);
        $this->login($this->owner);
    }

    public function testAdvanceDragAndFormMovesAreRefusedWithTheMessage(): void
    {
        $task = $this->task($this->board, 'Stuck');

        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/advance', ['_token' => PredictableCsrfTokenManager::value('task_board_task_advance'), 'action' => 'next']);
        self::assertSame('To do', $this->reload($task)->getColumn()?->getName());
        self::assertSame(['web', 'move'], [ScriptedTransitionPolicy::$asked[0]->via->channel, ScriptedTransitionPolicy::$asked[0]->via->operation]);

        $this->client->xmlHttpRequest('POST', self::BASE . '/task/' . $task->getId() . '/move', ['columnId' => $this->column('Blocked')->getId(), 'position' => 0, '_token' => PredictableCsrfTokenManager::value('task_board_task_move')]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['refused' => 'BLOCK_REASON_REQUIRED', 'message' => 'A blocked task needs a reason'], json_decode((string) $this->client->getResponse()->getContent(), true));

        ScriptedTransitionPolicy::$message = '';
        $this->client->request('POST', self::BASE . '/task/' . $task->getId() . '/move', ['columnId' => $this->column('Blocked')->getId(), 'position' => 0, '_token' => PredictableCsrfTokenManager::value('task_board_task_move')]);
        self::assertResponseRedirects();

        $this->client->request('POST', self::BASE . '/task/' . $task->getId(), ['task_form' => [
            'title'    => 'Stuck',
            'columnId' => $this->column('Blocked')->getId(),
            '_token'   => PredictableCsrfTokenManager::value('task_form'),
        ]]);
        self::assertSame('To do', $this->reload($task)->getColumn()?->getName());
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $this->board->getId());
        self::assertStringContainsString('The task was not moved: this column does not accept it from here.', $crawler->text());
        self::assertStringContainsString('A blocked task needs a reason', $crawler->text());
    }

    public function testCreatesInARefusedColumnAreRefused(): void
    {
        $parent = $this->task($this->board, 'Parent');
        $this->client->request('POST', self::BASE . '/board/' . $this->board->getId() . '/create', ['task_form' => ['title' => 'New', 'columnId' => $this->column('Blocked')->getId(), '_token' => PredictableCsrfTokenManager::value('task_form')]]);
        $this->client->request('POST', self::BASE . '/task/' . $parent->getId() . '/subtask', ['task_form' => ['title' => 'Child', 'columnId' => $this->column('Blocked')->getId(), '_token' => PredictableCsrfTokenManager::value('task_form')]]);
        self::assertSame([], $this->em->getRepository(Task::class)->findBy(['title' => ['New', 'Child']]));
        self::assertTrue(ScriptedTransitionPolicy::$asked[0]->isCreation());
        self::assertSame('create', ScriptedTransitionPolicy::$asked[0]->via->operation);
    }

    public function testTheColumnDialogsCarryTheHumansOnlyToggle(): void
    {
        $blocked = $this->column('Blocked');
        $this->client->request('POST', self::BASE . '/board/' . $this->board->getId() . '/column/' . $blocked->getId(), ['board_column_form' => ['name' => 'Blocked', 'humansOnly' => '1', '_token' => PredictableCsrfTokenManager::value('board_column_form')]]);
        $this->em->clear();
        self::assertTrue($this->em->find(BoardColumn::class, $blocked->getId())?->isHumansOnly());

        $crawler = $this->client->request('GET', self::BASE . '/board/' . $this->board->getId());
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-column-humans-only"]'));
        self::assertCount(1, $crawler->filter('[data-column-humans-only="1"]'));
        self::assertGreaterThan(0, $crawler->filter('input[name="board_column_form[humansOnly]"]')->count());

        $manager = self::getContainer()->get(BoardColumnManager::class);
        self::assertInstanceOf(BoardColumnManager::class, $manager);
        $column = $this->em->find(BoardColumn::class, $blocked->getId());
        self::assertInstanceOf(BoardColumn::class, $column);
        self::assertFalse($manager->setHumansOnly($column, true, $this->owner));
        self::assertTrue($manager->setHumansOnly($column, false, $this->owner));
        $this->em->clear();
        self::assertFalse($this->em->find(BoardColumn::class, $blocked->getId())?->isHumansOnly());
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

    private function reload(Task $task): Task
    {
        $this->em->clear();
        $found = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $found);
        $this->board = $found->getBoard();

        return $found;
    }
}
