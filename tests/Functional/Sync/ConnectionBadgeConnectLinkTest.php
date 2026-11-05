<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;

use function array_replace_recursive;

/**
 * Since 1.25.2: `sync.badge.connect_link: false` hides the connection badge of an unlinked board (no Connect link, no
 * empty strip) on the board, list and Gantt pages without a template override; a linked board keeps its badge.
 */
final class ConnectionBadgeConnectLinkTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['badge' => ['connect_link' => false]]]);
    }

    public function testAnUnlinkedBoardShowsNoBadgeAndALinkedBoardKeepsIt(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        foreach (['?view=board', '/list', '/gantt'] as $view) {
            $crawler = $this->client->request('GET', self::BASE . $board->getId() . $view);
            self::assertResponseIsSuccessful($view);
            self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-badge"]'), $view);
            self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-connect-link"]'), $view);
        }

        // The connection page itself stays reachable (the host links it from elsewhere).
        $this->client->request('GET', self::BASE . $board->getId() . '/connection');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', self::BASE . $board->getId() . '/connection/link', ['task_board_sync_link' => [
            'provider' => 'clickup', 'connection' => 'conn-1', 'container' => self::LIST,
            '_token'   => PredictableCsrfTokenManager::value('task_board_connection_link'),
        ]]);
        self::assertResponseRedirects(self::BASE . $board->getId() . '/connection');

        $this->client->request('GET', self::BASE . $board->getId() . '?view=board');
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-badge"]', 'ClickUp · Backlog');
        self::assertSelectorExists('[data-testid="nowo-task-sync-header-refresh"]');
    }
}
