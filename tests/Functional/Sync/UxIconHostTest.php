<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Tests\Functional\App\TablerOnlyUxIcons;
use Throwable;

use function array_replace_recursive;

/**
 * Since 2.0.2: every 2.0.0 page on a host with `icon_set: ux_icon` that imported Tabler only ({@see TablerOnlyUxIcons}
 * throws on any other name). The demo does not use ux_icon, which is how the index 500 of 2.0.0 shipped.
 */
final class UxIconHostTest extends SyncPeopleFunctionalTestCase
{
    private const BASE = '/tools/task-board';

    /** @var array<string, mixed> `nowo_ui_kit` configuration of the next kernel */
    private static array $uiKit = ['icon_set' => 'ux_icon'];

    protected static function syncConfig(): array
    {
        // Since 2.1.0 (spec 214): with the calendar and the task numbers on, whose pages must not ask for a bare icon either.
        return array_replace_recursive(parent::syncConfig(), ['__ui_kit' => self::$uiKit, 'board' => ['column_icons' => ['tabler:bug']], 'calendar' => ['enabled' => true], 'task_numbers' => ['enabled' => true]]);
    }

    protected function tearDown(): void
    {
        self::$uiKit = ['icon_set' => 'ux_icon'];
        parent::tearDown();
    }

    public function testEveryPageRendersOnAHostThatImportedOnlyTabler(): void
    {
        $this->login($this->owner);
        $this->client->catchExceptions(false);

        // No board yet: the empty state carries the New board action.
        $crawler = $this->client->request('GET', self::BASE);
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-new-empty"] svg[data-icon="tabler:plus"]'));

        $unlinked = $this->board('Unlinked');
        $board    = $this->board();
        $board->getColumns()->get(0)?->setIcon('tabler:bug');
        $this->em->flush();
        $task = $this->task($board, 'Write the spec');
        // A bar with its edges and Reschedule on the calendar, and a day with the Add control.
        $task->setStartAt(new DateTimeImmutable('2026-11-10'))->setDueAt(new DateTimeImmutable('2026-11-12'));
        $this->em->flush();
        $subtask = $this->task($board, 'Child', parent: $task);
        $linked  = $this->board('Linked');
        $this->workspace([self::member('1', 'owner@example.com')]);
        $this->put($this->remote('r1', 'Remote task'));
        $this->sync($linked);

        $failures = [];
        foreach ([
            self::BASE,
            self::BASE . '?new=1',
            self::BASE . '/board/' . $board->getId(),
            self::BASE . '/board/' . $board->getId() . '/list',
            self::BASE . '/board/' . $board->getId() . '/gantt',
            self::BASE . '/board/' . $board->getId() . '/calendar?date=2026-11-11',
            self::BASE . '/board/' . $board->getId() . '/calendar?date=2026-11-11&mode=week',
            self::BASE . '/board/' . $board->getId() . '/calendar?date=2026-11-11&mode=list',
            self::BASE . '/board/' . $linked->getId() . '/calendar',
            self::BASE . '/board/' . $board->getId() . '/import',
            self::BASE . '/task/' . $task->getId(),
            self::BASE . '/task/' . $task->getId() . '/panel',
            self::BASE . '/task/' . $subtask->getId(),
            self::BASE . '/board/' . $unlinked->getId() . '/connection',
            self::BASE . '/board/' . $linked->getId(),
            self::BASE . '/board/' . $linked->getId() . '/list',
            self::BASE . '/board/' . $linked->getId() . '/connection',
            self::BASE . '/board/' . $linked->getId() . '/connection/people',
        ] as $path) {
            try {
                $this->client->request('GET', $path);
                self::assertResponseIsSuccessful($path);
            } catch (Throwable $e) {
                $failures[$path] = $e->getMessage();
            }
        }

        self::assertSame([], $failures);

        // The number's short link, and the task page it leads to.
        $this->client->request('GET', self::BASE . '/n/' . $task->getNumber() . '?board=' . $board->getId());
        self::assertResponseRedirects(self::BASE . '/task/' . $task->getId());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.nowo-task-detail__number');
    }

    public function testTheNewBoardActionDrawsThePlus(): void
    {
        $this->board();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-new"] svg[data-icon="tabler:plus"]'));
    }

    /**
     * Since 2.1.1: New board is the page's main action, so `action_variants: {create: secondary}`, which is for the create
     * actions in rows and panels, leaves it primary. Both places it can be drawn.
     */
    public function testAHostCreateVariantLeavesNewBoardPrimary(): void
    {
        $this->reboot(['icon_set' => 'ux_icon', 'action_variants' => ['create' => 'secondary']]);
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="nowo-task-board-new-empty"]')->attr('class'));
        self::assertStringNotContainsString('btn-secondary', (string) $crawler->filter('[data-testid="nowo-task-board-new-empty"]')->attr('class'));

        $this->board();
        $crawler = $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('btn-primary', (string) $crawler->filter('[data-testid="nowo-task-board-new"]')->attr('class'));
        self::assertStringNotContainsString('btn-secondary', (string) $crawler->filter('[data-testid="nowo-task-board-new"]')->attr('class'));
    }

    public function testTheFiltersFoldDrawsTablerChevrons(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/list');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.nowo-ui-filters-collapse__icon--expanded svg[data-icon="tabler:chevron-up"]'));
        self::assertCount(1, $crawler->filter('.nowo-ui-filters-collapse__icon--collapsed svg[data-icon="tabler:chevron-down"]'));
    }

    public function testTheHostActionIconsDecide(): void
    {
        $this->reboot(['icon_set' => 'ux_icon', 'action_icons' => ['create' => 'tabler:circle-plus', 'chevron_up' => 'tabler:arrow-up', 'chevron_down' => 'tabler:arrow-down']]);
        $board = $this->board();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-new"] svg[data-icon="tabler:circle-plus"]'));

        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/list');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.nowo-ui-filters-collapse__icon--expanded svg[data-icon="tabler:arrow-up"]'));
        self::assertCount(1, $crawler->filter('.nowo-ui-filters-collapse__icon--collapsed svg[data-icon="tabler:arrow-down"]'));
    }

    public function testAWebfontHostKeepsItsPlusAndItsKindChevrons(): void
    {
        foreach (['tabler-icons' => 'ti', 'bootstrap-icons' => 'bi'] as $set => $class) {
            $this->reboot(['icon_set' => $set]);
            $board = $this->board();
            $this->login($this->owner);

            $crawler = $this->client->request('GET', self::BASE);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-new"] i.' . $class . '.' . $class . '-plus'), $set);

            $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/list');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('.nowo-ui-filters-collapse__icon--expanded i.' . $class . '-chevron-up'), $set);
        }
    }

    /**
     * @param array<string, mixed> $uiKit
     */
    private function reboot(array $uiKit): void
    {
        self::$uiKit = $uiKit;
        self::ensureKernelShutdown();
        $this->setUp();
    }
}
