<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Testing\InMemoryTaskProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_replace_recursive;

/**
 * Since 2.5.0 (TB-16): `sync.badge.style: strip` draws the connection as one UiKit notice strip — provider · list and the
 * status on the left (a progress bar while a run is going: polled for managers, indeterminate for readers), Refresh and
 * Connection on the right — on the board, list and Gantt pages.
 */
final class ConnectionStripTest extends SyncFunctionalTestCase
{
    private const BASE = '/tools/task-board/board/';

    protected static function syncConfig(): array
    {
        return array_replace_recursive(parent::syncConfig(), ['sync' => ['badge' => ['style' => 'strip', 'progress' => true]]]);
    }

    public function testManagersSeeTheRunningRunInTheStrip(): void
    {
        [$board, $runId] = $this->pausedRun();
        $this->login($this->owner);
        foreach (['?view=board', '/list', '/gantt'] as $view) {
            $strip = $this->strip($this->client->request('GET', self::BASE . $board->getId() . $view));
            self::assertResponseIsSuccessful($view);
            self::assertStringContainsString('nowo-ui-flash--info', (string) $strip->attr('class'), $view);
            self::assertSame('region', $strip->attr('role'));
            self::assertStringContainsString('ClickUp · Backlog', $strip->filter('.nowo-task-sync-strip__title')->text());
            $bar = $strip->filter('[data-testid="nowo-task-sync-badge-progress"]');
            self::assertSame(self::BASE . $board->getId() . '/connection/progress/' . $runId, $bar->attr('data-nowo-task-board-sync-progress-url-value'));
            self::assertCount(1, $bar->filter('.nowo-ui-progress-field progress[max="2"][value="1"][data-nowo-task-board-sync-progress-target="bar"]'), $view);
            self::assertCount(1, $bar->filter('[role="status"][data-nowo-task-board-sync-progress-target="status"]'));
            self::assertCount(1, $strip->filter('.nowo-task-sync-strip__actions [data-testid="nowo-task-sync-header-refresh"][disabled]'));
            self::assertCount(1, $strip->filter('[data-testid="nowo-task-sync-connection-link"]'));
        }
    }

    public function testReadersGetAnIndeterminateBarAndNoActions(): void
    {
        [$board]    = $this->pausedRun();
        $reader     = $this->user('reader@example.com');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($reader): void {
            if ($event->getSubject() === $reader && $event->getAction() === BoardAccessCheckEvent::ACTION_VIEW) {
                $event->grant();
            }
        });
        $this->login($reader);

        $strip = $this->strip($this->client->request('GET', self::BASE . $board->getId() . '?view=board'));

        self::assertCount(0, $strip->filter('[data-testid="nowo-task-sync-badge-progress"]'));
        self::assertCount(1, $strip->filter('.nowo-ui-progress-field--indeterminate progress:not([value])'));
        self::assertCount(1, $strip->filter('[role="status"]'));
        self::assertCount(0, $strip->filter('.nowo-task-sync-strip__actions'));
    }

    public function testAnUnlinkedAndAnIdleBoard(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE . $board->getId() . '?view=board');
        self::assertCount(1, $crawler->filter('.nowo-task-sync-strip--unlinked [data-testid="nowo-task-sync-connect-link"]'));

        $this->put($this->remote('d1', 'Done', 1));
        $this->sync($board);
        $strip = $this->strip($this->client->request('GET', self::BASE . $board->getId() . '?view=board'));
        self::assertCount(0, $strip->filter('progress'));
        self::assertCount(1, $strip->filter('[data-testid="nowo-task-sync-header-refresh"]:not([disabled])'));
        self::assertStringNotContainsString('is-running', (string) $strip->attr('class'));
    }

    private function strip(Crawler $crawler): Crawler
    {
        $strip = $crawler->filter('[data-testid="nowo-task-sync-badge"][data-nowo-task-sync-style="strip"]');
        self::assertCount(1, $strip);

        return $strip;
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
