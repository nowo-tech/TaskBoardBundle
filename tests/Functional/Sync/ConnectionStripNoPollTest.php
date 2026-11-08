<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;

use function array_replace_recursive;

/**
 * Since 2.5.0 (audit IM-I11): `sync.badge.style: strip` without `sync.badge.progress` never polls — managers get the
 * indeterminate bar, as readers do.
 */
final class ConnectionStripNoPollTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['badge' => ['style' => 'strip', 'progress' => false]]]);
    }

    public function testAManagerSeesTheRunWithoutPolling(): void
    {
        $board          = $this->board();
        $this->provider = new InMemoryTaskProvider(pageSize: 1);
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 2));
        $this->put($this->remote('z1', 'One', 1), $this->remote('z2', 'Two', 2));
        self::assertSame('paused', $this->sync($board, maxPages: 1)->status->value);
        $this->login($this->owner);

        $strip = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . '?view=board')->filter('[data-nowo-task-sync-style="strip"]');

        self::assertCount(1, $strip);
        self::assertCount(0, $strip->filter('[data-controller="nowo-task-board-sync-progress"], [data-nowo-task-board-sync-progress-url-value]'));
        self::assertCount(1, $strip->filter('.nowo-ui-progress-field--indeterminate progress:not([value])'));
        self::assertCount(1, $strip->filter('[data-testid="nowo-task-sync-header-refresh"]'));
    }
}
