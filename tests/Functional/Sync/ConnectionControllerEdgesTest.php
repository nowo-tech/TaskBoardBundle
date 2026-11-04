<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Dto\SyncStatus;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;

/**
 * Since 1.23: the connection pages' edges — users without access, a board already linked, an unknown provider, columns
 * with the same name, lists without a task count and a paused run advanced by the progress poll.
 */
final class ConnectionControllerEdgesTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    public function testUsersWithoutTaskBoardAccessAreRefused(): void
    {
        $board = $this->board();
        $this->login($this->user('guest@example.com', ['ROLE_GUEST']));
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertResponseStatusCodeSame(403);
    }

    public function testALinkedBoardCannotBrowseOrLinkAgainAndUnknownProvidersAre404(): void
    {
        $board = $this->board(columns: ['To do', 'To do', 'Done']);
        $this->provider->addContainer(new RemoteContainer('L2', 'No count', ContainerType::List, 's:1', true, false));
        $this->login($this->owner);
        $token = PredictableCsrfTokenManager::value('task_board_connection_containers');

        // A list without a task count shows its name only; two columns named alike stay apart.
        $this->client->xmlHttpRequest('POST', self::BASE . $board->getId() . '/connection/containers', ['_token' => $token, 'provider' => 'clickup', 'connection' => 'conn-1', 'parent' => 's:1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No count', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', self::BASE . $board->getId() . '/connection?open[]=w:1&open[]=s:1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('To do (2)', (string) $this->client->getResponse()->getContent());

        $link = static fn (string $provider): array => ['task_board_sync_link' => [
            'provider' => $provider, 'connection' => 'conn-1', 'container' => self::LIST,
            '_token'   => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]];
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', $link('nope'));
        self::assertResponseStatusCodeSame(404);

        $this->put($this->remote('k1', 'One', 1));
        $this->sync($board);
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', $link('clickup'));
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection');
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/containers', ['_token' => $token, 'provider' => 'clickup', 'connection' => 'conn-1']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheProgressPollAdvancesAPausedRun(): void
    {
        $board = $this->board();
        $small = new InMemoryTaskProvider(pageSize: 1);
        $small->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false));
        foreach ([1, 2, 3] as $i) {
            $small->putTask(self::LIST, $this->remote('p' . $i, 'Task ' . $i, $i));
            $this->put($this->remote('p' . $i, 'Task ' . $i, $i));
        }
        $first = $this->sync($board, $small, maxPages: 1);
        self::assertSame(SyncStatus::Paused, $first->status);

        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $board->getId() . '/connection/progress/' . $first->runId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"status":"succeeded"', (string) $this->client->getResponse()->getContent());
    }
}
