<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Nowo\TaskBoardBundle\Sync\View\ConnectionBadgeProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_replace_recursive;
use function json_decode;

/**
 * Since 1.26.3 (TB-SYNC-1): the badge data carries the running run (id, progress URL for managers, total and processed)
 * so a header can show a determinate bar; with `sync.badge.progress` the bundle's own badge renders it on the board,
 * list and Gantt pages. Without the option the default output does not change.
 */
final class ConnectionBadgeProgressTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['badge' => ['progress' => true], 'progress' => ['poll_interval_ms' => 1500]]]);
    }

    public function testTheHeaderShowsTheRunningRunAndItsProgressToManagers(): void
    {
        [$board, $runId] = $this->pausedRun();
        $this->login($this->owner);
        foreach (['?view=board', '/list', '/gantt'] as $view) {
            $crawler = $this->client->request('GET', self::BASE . $board->getId() . $view);
            self::assertResponseIsSuccessful($view);
            $bar = $crawler->filter('[data-testid="nowo-task-sync-badge-progress"]');
            self::assertCount(1, $bar, $view);
            self::assertSame(self::BASE . $board->getId() . '/connection/progress/' . $runId, $bar->attr('data-nowo-task-board-sync-progress-url-value'));
            self::assertSame('1500', $bar->attr('data-nowo-task-board-sync-progress-interval-value'));
            self::assertCount(1, $bar->filter('progress[max="2"][value="1"]'), $view);
            self::assertCount(1, $bar->filter('[role="status"][aria-live="polite"]'), $view);
            self::assertCount(1, $bar->filter('a[hidden][data-nowo-task-board-sync-progress-target="done"]'), $view);
        }

        // The endpoint the header polls answers the same counters.
        $this->client->request('GET', self::BASE . $board->getId() . '/connection/progress/' . $runId);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertSame(2, $json['total']);
    }

    public function testReadersGetTheRunWithoutTheProgressUrl(): void
    {
        [$board, $runId] = $this->pausedRun();
        $reader          = $this->user('reader@example.com');
        $dispatcher      = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($reader): void {
            if ($event->getSubject() === $reader && $event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->grant();
            }
        });
        $this->login($reader);
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '?view=board');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-sync-badge"]'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-badge-progress"]'));

        $badges = self::getContainer()->get(ConnectionBadgeProvider::class);
        self::assertInstanceOf(ConnectionBadgeProvider::class, $badges);
        $data = $badges->forBoard($this->freshBoard($board));
        self::assertNotNull($data);
        self::assertFalse($data['canManage']);
        self::assertSame($runId, $data['activeRunId']);
        self::assertNull($data['progressUrl']);
        self::assertSame(2, $data['total']);
        self::assertSame(1, $data['processed']);
    }

    public function testAnIdleOrUnlinkedBoardHasNoRun(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        $badges = self::getContainer()->get(ConnectionBadgeProvider::class);
        self::assertInstanceOf(ConnectionBadgeProvider::class, $badges);
        $this->client->request('GET', self::BASE . $board->getId() . '?view=board');
        $unlinked = $badges->forBoard($board);
        self::assertNotNull($unlinked);
        self::assertFalse($unlinked['linked']);
        self::assertNull($unlinked['activeRunId']);
        self::assertSame(0, $unlinked['processed']);
        self::assertTrue($unlinked['progress']);
        self::assertSame(1500, $unlinked['pollInterval']);

        $this->put($this->remote('d1', 'Done', 1));
        $this->sync($board);
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '?view=board');
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-sync-badge-progress"]'));
        $idle = $badges->forBoard($this->freshBoard($board));
        self::assertNotNull($idle);
        self::assertNull($idle['activeRunId']);
        self::assertNull($idle['progressUrl']);
        self::assertNull($idle['total']);
    }

    /**
     * @return array{0: TaskBoard, 1: string}
     */
    private function pausedRun(): array
    {
        $board          = $this->board();
        $this->provider = new InMemoryTaskProvider(pageSize: 1);
        $this->provider->addContainer(new RemoteContainer(self::LIST, 'Backlog', ContainerType::List, null, true, false, 2));
        $this->put($this->remote('z1', 'One', 1), $this->remote('z2', 'Two', 2));
        $first = $this->sync($board, maxPages: 1);
        self::assertSame('paused', $first->status->value);

        return [$board, $first->runId];
    }
}
