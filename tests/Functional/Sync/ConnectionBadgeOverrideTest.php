<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use function array_replace_recursive;

/**
 * Since 1.25: `templates.connection_badge` replaces the connection badge of the board, list and Gantt pages (the three
 * headers used to include the bundle's partial by a fixed path).
 */
final class ConnectionBadgeOverrideTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['templates' => ['connection_badge' => 'connection_badge_override.html.twig']]);
    }

    public function testTheConfiguredBadgeIsUsedOnTheBoardListAndGanttPages(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        foreach (['', '/list', '/gantt'] as $view) {
            $crawler = $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . $view . ($view === '' ? '?view=board' : ''));
            self::assertResponseIsSuccessful($view);
            self::assertCount(1, $crawler->filter('[data-testid="host-connection-badge"]'), $view);
            self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-badge"]'), $view);
        }
    }
}
