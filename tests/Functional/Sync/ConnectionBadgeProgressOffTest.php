<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Sync\View\ConnectionBadgeProvider;

/**
 * Since 1.26.3 (TB-SYNC-1): without `sync.badge.progress` the default badge renders as before; the run data is there
 * for a host template.
 */
final class ConnectionBadgeProgressOffTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    public function testWithoutTheOptionTheDefaultBadgeIsUnchanged(): void
    {
        $board          = $this->board();
        $this->provider = new InMemoryTaskProvider(pageSize: 1);
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 2));
        $this->put($this->remote('z1', 'One', 1), $this->remote('z2', 'Two', 2));
        $runId = $this->sync($board, maxPages: 1)->runId;
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '?view=board');
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-badge-progress"]'));
        self::assertSelectorTextContains('[data-testid="nowo-task-sync-badge"]', 'Sync');

        $badges = self::getContainer()->get(ConnectionBadgeProvider::class);
        self::assertInstanceOf(ConnectionBadgeProvider::class, $badges);
        $data = $badges->forBoard($this->freshBoard($board));
        self::assertNotNull($data);
        self::assertFalse($data['progress']);
        self::assertSame($runId, $data['activeRunId']);
        self::assertSame(self::BASE . $board->getId() . '/connection/progress/' . $runId, $data['progressUrl']);
    }
}
