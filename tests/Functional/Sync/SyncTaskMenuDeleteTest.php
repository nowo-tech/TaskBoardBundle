<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Menu\TaskMenuOperation;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;

/**
 * Since 2.22.0: Delete of the task menu on a task that follows a provider is refused with a flash (the next sync would
 * bring it back), as the eraser refuses it everywhere; the task stays.
 */
final class SyncTaskMenuDeleteTest extends SyncFunctionalTestCase
{
    public function testASyncedTaskIsNotDeletedFromTheMenu(): void
    {
        $board = $this->board();
        $this->put($this->remote('s1', 'Synced', 1));
        $this->sync($board);
        $synced = $this->taskTitled('Synced');
        $this->login($this->owner);

        $this->client->request('POST', '/tools/task-board/menu/task', [
            'action' => TaskMenuOperation::value($synced->getId(), TaskMenuOperation::DELETE),
            '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID),
        ]);

        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('task_board.menu.flash.delete_linked', $crawler->text());
        $this->em->clear();
        self::assertInstanceOf(Task::class, $this->em->find(Task::class, $synced->getId()));
    }
}
