<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteRole;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedPeopleResolver;

/**
 * Since 3.4.0: without `sync.people_forget` there is no button and the route answers 404.
 */
final class ForgetPersonOffTest extends SyncPeopleFunctionalTestCase
{
    protected function setUp(): void
    {
        ScriptedPeopleResolver::reset();
        parent::setUp();
    }

    public function testOff(): void
    {
        $board = $this->boardWithColumns('To do');
        $this->workspace([new RemotePerson('1', 'One', 'one@remote.test', RemoteRole::Member)]);
        $this->put($this->row('t1', 'One task', 1, assignees: [self::ref('1', 'one@remote.test', 'One')]));
        $this->sync($board);
        $this->em->clear();
        $this->refreshOwner();
        $person = $this->personOf('1');
        $this->login($this->owner);
        $page = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/connection/people');
        self::assertCount(0, $page->filter('[data-testid="nowo-task-sync-person-forget"]'));
        $this->client->request('POST', '/tools/task-board/board/' . $board->getId() . '/connection/people/' . $person->getId() . '/forget', ['task_board_sync_people_forget' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_people_forget')]]);
        self::assertResponseStatusCodeSame(404);
    }
}
