<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Menu;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Since 2.22.0: `web_ui.context_menus` (null follows UiKit's `theme.context_menus`, true / false forces it) marks the
 * card, row and column menus as the sources of UiKit's context menu; the focus mode toggle (UiKit 2.55, area `tasks`) is
 * on the board, list, calendar and task pages only when the host turned focus mode on.
 */
final class MenuPageOptionsTest extends FunctionalTestCase
{
    private TaskBoard $board;

    private Task $task;

    public function testOffByDefaultNeitherContextMenuNorFocusToggle(): void
    {
        $this->boot([]);
        $crawler = $this->page('?view=board');

        self::assertCount(0, $crawler->filter('[data-nowo-ui-context-menu-source]'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-focus-toggle"]'));
        self::assertCount(0, $crawler->filter('script[src*="nowo-ui-context-menu.js"]'));
        self::assertCount(1, $crawler->filter('script[src*="nowo-ui-clipboard.js"]'));
    }

    public function testTheThemeFlagTurnsTheContextMenusOn(): void
    {
        $this->boot(['__ui_kit' => ['theme' => ['context_menus' => true]]]);
        $crawler = $this->page('?view=board');

        // One card menu and one menu per column header (three columns).
        self::assertCount(4, $crawler->filter('[data-nowo-ui-context-menu-source]'));
        self::assertCount(1, $this->page('/list')->filter('tr [data-nowo-ui-context-menu-source]'));
    }

    public function testTheBundleOptionForcesThemOnAndLoadsTheRuntime(): void
    {
        $this->boot(['web_ui' => ['context_menus' => true]]);
        $crawler = $this->page('?view=board');

        self::assertCount(4, $crawler->filter('[data-nowo-ui-context-menu-source]'));
        self::assertCount(1, $crawler->filter('script[src*="nowo-ui-context-menu.js"]'));
        self::assertCount(1, $this->page('/list')->filter('script[src*="nowo-ui-context-menu.js"]'));
    }

    public function testTheBundleOptionForcesThemOffUnderTheThemeFlag(): void
    {
        $this->boot(['web_ui' => ['context_menus' => false], '__ui_kit' => ['theme' => ['context_menus' => true]]]);

        self::assertCount(0, $this->page('?view=board')->filter('[data-nowo-ui-context-menu-source]'));
        self::assertCount(0, $this->page('/list')->filter('[data-nowo-ui-context-menu-source]'));
    }

    public function testTheFocusToggleIsOnEveryTaskView(): void
    {
        $this->boot(['calendar' => ['enabled' => true], '__ui_kit' => ['theme' => ['focus_mode' => ['enabled' => true]]]]);

        foreach (['?view=board', '/list', '/calendar'] as $view) {
            $toggle = $this->page($view)->filter('[data-testid="nowo-task-focus-toggle"] [data-nowo-ui-focus-mode]');
            self::assertCount(1, $toggle, $view);
            self::assertSame('tasks', $toggle->attr('data-nowo-ui-focus-area'), $view);
        }
        $task = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('tasks', $task->filter('[data-testid="nowo-task-focus-toggle"] [data-nowo-ui-focus-mode]')->attr('data-nowo-ui-focus-area'));
        // The side panel is not a page: no toggle there.
        $panel = $this->client->request('GET', '/tools/task-board/task/' . $this->task->getId() . '/panel');
        self::assertCount(0, $panel->filter('[data-testid="nowo-task-focus-toggle"]'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = $config;
        $this->setUp();
        $this->board = $this->board();
        $this->task  = $this->task($this->board, 'Plan the launch');
        $this->login($this->owner);
    }

    private function page(string $view): Crawler
    {
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId() . $view);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
