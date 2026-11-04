<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncRun;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

use function json_decode;

/**
 * Since 1.23: the connection pages over HTTP — who reaches them (404 otherwise), CSRF on every action, the container
 * browser with and without script, link, refresh, progress, the mass-archive confirmation, cancel and unlink.
 */
final class ConnectionControllerTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    public function testOnlyManagersReachTheConnectionPages(): void
    {
        $board = $this->board();
        $this->login($this->user('reader@example.com'));
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/unlink', ['_token' => PredictableCsrfTokenManager::value('task_board_connection_unlink')]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::BASE . 'nope/connection');
        self::assertResponseStatusCodeSame(404);

        // The board header: nothing for someone who cannot view the board; Connect provider for its manager.
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $board->getId());
        self::assertSelectorExists('[data-testid="nowo-task-sync-connect-link"]');
    }

    public function testConnectBrowseLinkRefreshAndUnlink(): void
    {
        $board = $this->board();
        $this->put($this->remote('p1', 'Plan', 1), $this->remote('p2', 'Build', 2));
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-connect"]', 'Workspace 1');
        self::assertSelectorExists('button[aria-expanded="false"][aria-controls]');
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-list"]'), 'lists appear once their space is open');

        // With script: one level as a fragment.
        $this->client->xmlHttpRequest('POST', self::BASE . $board->getId() . '/connection/containers', [
            '_token' => PredictableCsrfTokenManager::value('task_board_connection_containers'), 'provider' => 'clickup', 'connection' => 'conn-1', 'parent' => 's:1',
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Backlog — 3 tasks', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('name="task_board_sync_link[container]"', (string) $this->client->getResponse()->getContent());

        // Without script: a redirect to the page with the level open.
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/containers?open[]=w:1', [
            '_token' => PredictableCsrfTokenManager::value('task_board_connection_containers'), 'task_board_sync_link' => ['provider' => 'clickup', 'connection' => 'conn-1'], 'parent' => 's:1',
        ]);
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-sync-list"]'));

        // Missing CSRF on the browser: 403; a bad parent: 404.
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/containers', ['provider' => 'clickup', 'connection' => 'conn-1']);
        self::assertResponseStatusCodeSame(403);
        $this->client->xmlHttpRequest('POST', self::BASE . $board->getId() . '/connection/containers', ['_token' => PredictableCsrfTokenManager::value('task_board_connection_containers'), 'provider' => 'clickup', 'connection' => 'conn-1', 'parent' => '../x']);
        self::assertResponseStatusCodeSame(404);

        // Link without a list: back with an error; then with the list.
        $link = static fn (string $container): array => ['task_board_sync_link' => [
            'provider'       => 'clickup', 'connection' => 'conn-1', 'container' => $container, 'createMissingColumns' => '1',
            'importComments' => '1', 'importAttachments' => '1', 'archiveRemoteDeletions' => '1',
            '_token'         => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]];
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', $link(''));
        self::assertResponseRedirects();
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', $link('unknown'));
        self::assertResponseRedirects();
        self::assertNull($this->connectionOf($board));
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', ['task_board_sync_link' => ['provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST]]);
        self::assertResponseStatusCodeSame(403, 'no CSRF token');

        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', $link(self::LIST));
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="nowo-task-sync-linked"]');
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-history"]', '2 created');
        self::assertCount(2, $this->em->getRepository(Task::class)->findAll());

        // The board header now shows the badge and a Refresh form.
        $this->client->request('GET', self::BASE . $board->getId());
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-badge"]', 'ClickUp · Backlog');

        // Refresh: CSRF required; the full option.
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/refresh', []);
        self::assertResponseStatusCodeSame(403);
        $this->put($this->remote('p3', 'Ship', 3));
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/refresh', ['task_board_sync_refresh' => ['full' => '1', '_token' => PredictableCsrfTokenManager::value('task_board_connection_refresh')]]);
        self::assertResponseRedirects();
        self::assertCount(3, $this->em->getRepository(Task::class)->findAll());

        // Unlink: CSRF required; the tasks stay.
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/unlink', []);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/unlink', ['_token' => PredictableCsrfTokenManager::value('task_board_connection_unlink')]);
        self::assertResponseRedirects(self::BASE . $board->getId());
        self::assertNull($this->connectionOf($board));
        self::assertCount(3, $this->em->getRepository(Task::class)->findAll());
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/refresh', ['task_board_sync_refresh' => ['_token' => PredictableCsrfTokenManager::value('task_board_connection_refresh')]]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testProgressAdvancesAPausedRunAndCancelStopsIt(): void
    {
        $board = $this->board();
        for ($i = 1; $i <= 7; ++$i) {
            $this->put($this->remote('q' . $i, 'Task ' . $i, $i));
        }
        // A run paused after one page (as the inline runner leaves a long one).
        $first = $this->sync($board, maxPages: 1);
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . $board->getId() . '/connection/progress/' . $first->runId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertSame('succeeded', $json['status']);
        self::assertTrue($json['terminal']);
        self::assertStringContainsString('7 created', (string) $json['summary']);

        $this->client->request('GET', self::BASE . $board->getId() . '/connection/progress/nope');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', self::BASE . $board->getId() . '/connection/cancel/' . $first->runId, []);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/cancel/' . $first->runId, ['_token' => PredictableCsrfTokenManager::value('task_board_connection_cancel')]);
        self::assertResponseRedirects();
    }

    public function testAPausedRunShowsItsProgressAndCanBeCancelled(): void
    {
        $board          = $this->board();
        $this->provider = new InMemoryTaskProvider(pageSize: 1);
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 2));
        $this->put($this->remote('z1', 'One', 1), $this->remote('z2', 'Two', 2));
        $first = $this->sync($board, maxPages: 1);
        self::assertSame('paused', $first->status->value);

        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertSelectorExists('[data-testid="nowo-task-sync-progress"] progress[max="2"]');
        self::assertSelectorExists('[role="status"][aria-live="polite"]');
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/cancel/' . $first->runId, ['_token' => PredictableCsrfTokenManager::value('task_board_connection_cancel')]);
        $this->em->clear();
        $run = $this->em->find(TaskSyncRun::class, $first->runId);
        self::assertSame('cancelled', $run?->getStatus()->value);
        self::assertNull($this->connectionOf($board)?->getCursor());
    }

    public function testTheMassArchiveGuardIsShownWithAConfirmPathForManagers(): void
    {
        $board = $this->board();
        $this->put($this->remote('m1', 'One', 1), $this->remote('m2', 'Two', 2));
        $this->sync($board);
        $this->provider->removeTask(self::LIST, 'm1')->removeTask(self::LIST, 'm2');
        $this->sync($board);

        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-mass-archive"]', '2 of 2 linked tasks were not archived');
        self::assertSelectorExists('dialog[data-dialog="mass-archive"] [data-testid="nowo-task-sync-mass-archive-confirm"]');

        // The plain refresh token does not confirm; the archive token does.
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/refresh', ['task_board_sync_refresh' => ['allowMassArchive' => '1', '_token' => PredictableCsrfTokenManager::value('task_board_connection_refresh')]]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::BASE . $board->getId() . '/connection/refresh', ['task_board_sync_refresh' => ['allowMassArchive' => '1', '_token' => PredictableCsrfTokenManager::value('task_board_connection_refresh_archive')]]);
        self::assertResponseRedirects();
        $this->em->clear();
        foreach ($this->em->getRepository(Task::class)->findAll() as $task) {
            self::assertTrue($task->isArchived());
        }

        // The list view lists them with Restore.
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '/list');
        self::assertSelectorTextContains('[data-testid="nowo-task-archived"]', 'Archived tasks (2)');
        $form = $crawler->filter('[data-testid="nowo-task-archived"] form')->first();
        $this->client->request('POST', (string) $form->attr('action'), ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-testid="nowo-task-archived"]', 'Archived tasks (1)');
    }

    public function testProviderErrorsNoConnectionAndSeveralConnections(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        $this->provider->failNext(new ProviderException(ProviderErrorCode::AuthFailed));
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-tree-error"]', 'The provider refused the token');
        self::assertSelectorExists('[data-testid="nowo-task-sync-tree-error"] a[href="/settings/connections/clickup"]');

        $this->provider->failNext(new ProviderException(ProviderErrorCode::Forbidden));
        $this->client->xmlHttpRequest('POST', self::BASE . $board->getId() . '/connection/containers', ['_token' => PredictableCsrfTokenManager::value('task_board_connection_containers'), 'provider' => 'clickup', 'connection' => 'conn-1', 'parent' => 'w:1']);
        self::assertStringContainsString('may not read this list', (string) $this->client->getResponse()->getContent());

        TestCredentialResolver::$choices = [];
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertSelectorExists('[data-testid="nowo-task-sync-no-connection"]');
        $this->client->request('GET', self::BASE . $board->getId() . '/connection?provider=nope');
        self::assertResponseIsSuccessful();

        TestCredentialResolver::$choices = [new ConnectionChoice('conn-1', 'One', 'aaaa'), new ConnectionChoice('conn-2', 'Two', 'bbbb')];
        $this->client->request('GET', self::BASE . $board->getId() . '/connection?connection=conn-1');
        self::assertSelectorExists('a[aria-current="true"]');
        // conn-2 has no credential: the page answers 404 (the credential is gone).
        $this->client->request('GET', self::BASE . $board->getId() . '/connection?connection=conn-2');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheProgressOfAnotherBoardsRunIs404(): void
    {
        $board = $this->board();
        $this->put($this->remote('o1', 'One', 1));
        $report = $this->sync($board);
        $other  = $this->board('Other');
        $this->login($this->owner);
        $this->client->request('GET', self::BASE . $other->getId() . '/connection/progress/' . $report->runId);
        self::assertResponseStatusCodeSame(404);
        self::assertInstanceOf(TaskBoardConnection::class, $this->connectionOf($board));
    }
}
