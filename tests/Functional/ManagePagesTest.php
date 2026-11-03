<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

/**
 * Every GET page of the manage UI renders for the board's creator.
 */
final class ManagePagesTest extends FunctionalTestCase
{
    public function testTheBoardPagesRender(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Write the spec');
        $this->task($board, 'Child', parent: $task);
        $this->login($this->owner);

        foreach (['/tools/task-board', '/tools/task-board/board/' . $board->getId(), '/tools/task-board/board/' . $board->getId() . '/list', '/tools/task-board/board/' . $board->getId() . '/gantt', '/tools/task-board/board/' . $board->getId() . '/import', '/tools/task-board/task/' . $task->getId(), '/tools/task-board/task/' . $task->getId() . '/panel'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testABoardOverTheCapShowsHowManyTasksThePageLoaded(): void
    {
        self::$taskBoardConfig = ['board' => ['max_tasks' => 2]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        foreach (['One', 'Two', 'Three'] as $position => $title) {
            $this->task($board, $title, position: $position);
        }
        $this->login($this->owner);

        foreach (['', '/list', '/gantt'] as $view) {
            $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . $view);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-testid="task-board-window-notice"]', 'Showing 2 of 3 tasks');
            self::assertSelectorTextNotContains('main, body', 'Three');
        }

        self::$taskBoardConfig = [];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        $this->task($board, 'Alone');
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId());
        self::assertSelectorNotExists('[data-testid="task-board-window-notice"]');
    }
}
