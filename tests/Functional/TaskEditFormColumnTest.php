<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Nowo\TaskBoardBundle\Tests\Functional\Characterization\CapturesUiOutput;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.2.1: a column change through the task's edit form is a move, as on the board: the host's limit is asked before
 * anything is saved, the completion follows the column, and the completed / reopened events fire once.
 */
final class TaskEditFormColumnTest extends SyncFunctionalTestCase
{
    use CapturesUiOutput;

    private const BASE = '/tools/task-board/';

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_COMPLETED, TaskBoardEvents::TASK_REOPENED] as $name) {
            $dispatcher->addListener($name, function () use ($name): void {
                $this->events[] = $name;
            });
        }
    }

    public function testAReopeningOverTheLimitIsRefusedWithNothingSaved(): void
    {
        $board = $this->board(columns: ['To do', 'Finished']);
        $task  = (new Task(board: $board, title: 'Done', creator: $this->owner, column: $this->column($board, 'Finished')))->markCompleted();
        $this->em->persist($task);
        $this->em->flush();
        $this->login($this->owner);
        TestSyncLimitChecker::$refuseTasks = true;
        $this->logger()->records           = [];

        $this->edit($task, 'Renamed', $this->column($board, 'To do'));

        $captured = $this->captured();
        self::assertSame(302, $captured['status']);
        self::assertSame(['error' => ['The task was not moved: reopening it would go over the limit of active tasks.']], $captured['flashes']);
        self::assertSame([], $captured['writes']);
        self::assertSame(['task_reopen_limit'], $captured['refused']);
        self::assertSame([[1, 0]], TestSyncLimitChecker::$calls);
        self::assertSame([], $this->events);
        $this->em->clear();
        $kept = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $kept);
        self::assertSame('Done', $kept->getTitle(), 'nothing of the form is saved');
        self::assertSame('Finished', $kept->getColumn()?->getName());
        self::assertTrue($kept->isCompleted());
        self::assertSame([], $this->em->getRepository(TaskChangeHistory::class)->findBy(['task' => $kept]));

        TestSyncLimitChecker::$refuseTasks = false;
        $board                             = $kept->getBoard();
        $this->edit($kept, 'Renamed', $this->column($board, 'To do'));
        $this->em->clear();
        $reopened = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $reopened);
        self::assertSame(['Renamed', 'To do', false], [$reopened->getTitle(), $reopened->getColumn()?->getName(), $reopened->isCompleted()]);
        self::assertSame([TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_REOPENED], $this->events);
    }

    public function testCompletingThroughTheFormCompletesOnceWithItsEvent(): void
    {
        $board = $this->board(columns: ['To do', 'Finished']);
        $task  = $this->task($board, 'Open');
        $this->login($this->owner);

        $this->edit($task, 'Open', $this->column($board, 'Finished'));

        self::assertResponseRedirects();
        self::assertSame([TaskBoardEvents::TASK_UPDATED, TaskBoardEvents::TASK_MOVED, TaskBoardEvents::TASK_COMPLETED], $this->events);
        self::assertSame([], TestSyncLimitChecker::$calls, 'completing adds no active task');
        $this->em->clear();
        $done = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $done);
        self::assertTrue($done->isCompleted());
        self::assertNotNull($done->getCompletedAt());
        self::assertSame('Finished', $done->getColumn()?->getName());

        // The same column again: nothing moves, nothing completes twice.
        $this->events = [];
        $this->edit($done, 'Open', $this->column($done->getBoard(), 'Finished'));
        self::assertSame([], $this->events);
    }

    /** A page left open while the task moved posts the column it showed: the task stays where it is now. */
    public function testAStalePageDoesNotMoveTheTaskBack(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Open');
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        self::assertSame($this->column($board, 'To do')->getId(), $crawler->filter('input[name="task_form[columnIdShown]"]')->attr('value'));

        $task->setColumn($this->column($board, 'Doing'));
        $this->em->flush();
        $form                     = $crawler->filter('form[name="task_form"]')->form();
        $form['task_form[title]'] = 'Renamed';
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertSame([TaskBoardEvents::TASK_UPDATED], $this->events);
        $this->em->clear();
        $kept = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $kept);
        self::assertSame(['Renamed', 'Doing'], [$kept->getTitle(), $kept->getColumn()?->getName()]);
    }

    /** A host template that does not post the shown column gets the posted column, as before 2.2.1. */
    public function testWithoutTheShownColumnThePostedColumnIsUsed(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Open');
        $this->login($this->owner);

        $this->client->request('POST', self::BASE . 'task/' . $task->getId(), ['task_form' => [
            'title'    => 'Open',
            'columnId' => $this->column($board, 'Doing')->getId(),
            '_token'   => PredictableCsrfTokenManager::value('task_form'),
        ]]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('Doing', $this->em->find(Task::class, $task->getId())?->getColumn()?->getName());
    }

    /** A column that is not the board's is a validation error on the column, and nothing is saved. */
    public function testAnUnknownColumnIsAValidationError(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Open');
        $this->login($this->owner);

        $crawler = $this->client->request('POST', self::BASE . 'task/' . $task->getId(), ['task_form' => [
            'title'         => 'Renamed',
            'columnId'      => 'not-a-column',
            'columnIdShown' => $this->column($board, 'To do')->getId(),
            '_token'        => PredictableCsrfTokenManager::value('task_form'),
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The selected choice is invalid.', $crawler->filter('.nowo-task-detail__head-meta')->text());
        self::assertSame([], $this->events);
        $this->em->clear();
        $kept = $this->em->find(Task::class, $task->getId());
        self::assertSame(['Open', 'To do'], [$kept?->getTitle(), $kept?->getColumn()?->getName()]);
    }

    private function column(TaskBoard $board, string $name): BoardColumn
    {
        foreach ($board->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column;
            }
        }
        self::fail('No column ' . $name);
    }

    /** Submits the task page's own edit form with a new title and column. */
    private function edit(Task $task, string $title, BoardColumn $column): void
    {
        $crawler                     = $this->client->request('GET', self::BASE . 'task/' . $task->getId());
        $form                        = $crawler->filter('form[name="task_form"]')->form();
        $form['task_form[title]']    = $title;
        $form['task_form[columnId]'] = $column->getId();
        $this->client->submit($form);
    }
}
