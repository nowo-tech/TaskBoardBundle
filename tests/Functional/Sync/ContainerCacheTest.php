<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Component\DomCrawler\Crawler;

use function array_filter;
use function count;
use function str_starts_with;

/**
 * Since 1.23: with `container_cache_ttl` on, a level of the container browser is asked once from the provider.
 */
final class ContainerCacheTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        $config                                = self::SYNC_CONFIG;
        $config['sync']['container_cache_ttl'] = 60;

        return $config;
    }

    public function testALevelIsCachedPerConnection(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        $post = fn (): Crawler => $this->client->xmlHttpRequest('POST', '/tools/task-board/board/' . $board->getId() . '/connection/containers', [
            '_token' => PredictableCsrfTokenManager::value('task_board_connection_containers'), 'provider' => 'clickup', 'connection' => 'conn-1', 'parent' => 's:1',
        ]);
        $post();
        self::assertResponseIsSuccessful();
        $post();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Backlog', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, count(array_filter($this->provider->calls, static fn (string $call): bool => str_starts_with($call, 'listContainers:s:1'))));
    }
}
