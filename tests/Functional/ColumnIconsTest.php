<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\UiKitBundle\Form\EmojiPickerType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use function array_filter;
use function array_map;
use function array_values;
use function preg_replace;
use function usort;

/**
 * Since 2.9.0: a column icon may be one emoji (`board.column_icon_form_type`, `board.default_columns[].icon`), validated on
 * every write path, printed as aria-hidden text; `nowo:task-board:apply-default-column-icons` fills existing boards.
 */
final class ColumnIconsTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    /** The Beacon example of the docs. */
    private const BEACON_COLUMNS = [
        ['name' => 'To do', 'color' => '#94a3b8', 'icon' => '📝'],
        ['name' => 'In progress', 'color' => '#3b82f6', 'icon' => '🚧'],
        ['name' => 'Review', 'color' => '#f59e0b', 'icon' => '👀'],
        ['name' => 'Done', 'color' => '#22c55e', 'icon' => '✅'],
    ];

    public function testTheDefaultDialogKeepsTheGridAndTablerIcons(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $board->getColumns()->first()?->setIcon('tabler:inbox');
        $this->em->flush();
        $this->login($this->owner);

        $html = $this->client->request('GET', self::BASE . '/board/' . $board->getId())->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('nowo-task-picker__icons', $html);
        self::assertStringNotContainsString('nowo-task-column__icon--emoji', $html);
        self::assertStringNotContainsString('data-nowo-ui-emoji-picker', $html);
        self::assertStringContainsString('data-column-icon="tabler:inbox"', $html);
    }

    public function testAnEmojiPickerColumnIconIsSavedAndPrintedAsText(): void
    {
        $this->boot(['board' => ['column_icon_form_type' => EmojiPickerType::class, 'column_icon_form_options' => ['bundle' => 'NowoTaskBoardBundle']]]);
        $board = $this->board(columns: ['To do', 'Done']);
        $this->login($this->owner);

        $html = $this->client->request('GET', self::BASE . '/board/' . $board->getId())->html();
        self::assertStringContainsString('data-nowo-ui-emoji-picker', $html);
        self::assertStringNotContainsString('nowo-task-picker__icons', $html, 'the host widget replaces the grid');
        self::assertStringContainsString('data-nowo-task-board-edit-column-modal-target="iconInput"', $html);

        $this->post('/board/' . $board->getId() . '/column', ['name' => 'Review', 'icon' => '👀']);
        self::assertResponseRedirects(self::BASE . '/board/' . $board->getId());
        $review = $this->column('Review');
        self::assertSame('👀', $review->getIcon());
        self::assertSame('Review', $review->getName(), 'the emoji is not part of the name');

        $html = $this->client->request('GET', self::BASE . '/board/' . $board->getId())->html();
        self::assertStringContainsString('<span class="nowo-task-column__icon nowo-task-column__icon--emoji" aria-hidden="true">👀</span>', $html);
        self::assertStringContainsString('data-column-icon="👀"', $html);

        foreach (['<b>x</b>', '👀👀', 'Done', 'tabler:inbox'] as $icon) {
            $this->post('/board/' . $board->getId() . '/column/' . $review->getId(), ['name' => 'Review', 'icon' => $icon]);
            self::assertResponseRedirects();
            self::assertSame(['error' => ['The column was not saved: its icon must be one emoji or one of the offered icons.']], $this->flashes(), $icon);
            self::assertSame('👀', $this->column('Review')->getIcon(), $icon);
        }

        $this->post('/board/' . $board->getId() . '/column/' . $review->getId(), ['name' => 'Review', 'icon' => '']);
        self::assertNull($this->column('Review')->getIcon());
    }

    /** Since 2.9.0 (audit JM-L1): with the grid, an emoji the grid does not offer survives an edit. */
    public function testTheGridKeepsAnEmojiItDoesNotOffer(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $todo  = $board->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $todo);
        $todo->setIcon('📝');
        $this->em->flush();
        $this->login($this->owner);
        $edit = '/board/' . $board->getId() . '/column/' . $todo->getId();

        $this->post($edit, ['name' => 'Backlog']);
        self::assertSame(['Backlog', '📝'], [$this->column('Backlog')->getName(), $this->column('Backlog')->getIcon()], 'not posted: kept');

        $this->post($edit, ['name' => 'Backlog', 'icon' => '📝']);
        self::assertSame('📝', $this->column('Backlog')->getIcon(), 'posted back by the dialog: a valid choice for this column');

        $this->flashes();
        $this->post($edit, ['name' => 'Backlog', 'icon' => '🚧']);
        self::assertSame(['error' => ['The column was not saved: its icon must be one emoji or one of the offered icons.']], $this->flashes(), 'another emoji is no choice of the grid');
        self::assertSame('📝', $this->column('Backlog')->getIcon());

        $this->post($edit, ['name' => 'Backlog', 'icon' => 'tabler:bug']);
        self::assertSame('tabler:bug', $this->column('Backlog')->getIcon());
        $this->post($edit, ['name' => 'Backlog', 'icon' => '']);
        self::assertNull($this->column('Backlog')->getIcon(), '"No icon" still removes it');
    }

    public function testATextWidgetTakesAnOfferedIconIdOrAnEmojiAndNothingElse(): void
    {
        $this->boot(['board' => ['column_icon_form_type' => TextType::class, 'column_icons' => ['tabler:bug'], 'default_columns' => [['name' => 'To do', 'icon' => 'tabler:inbox']]]]);
        $board = $this->board(columns: ['To do']);
        $this->login($this->owner);

        foreach (['tabler:bug' => 'tabler:bug', 'tabler:inbox' => 'tabler:inbox', '🚧' => '🚧', 'tabler:x' => '🚧', '<img src=x onerror=alert(1)>' => '🚧', 'ok' => '🚧'] as $icon => $stored) {
            $this->post('/board/' . $board->getId() . '/column/' . $board->getColumns()->first()?->getId(), ['name' => 'To do', 'icon' => $icon]);
            self::assertSame($stored, $this->column('To do')->getIcon(), $icon);
        }
        // A name error is no icon error: no flash, as before.
        $this->flashes();
        $this->post('/board/' . $board->getId() . '/column', ['name' => '', 'icon' => '🚧']);
        self::assertSame([], $this->flashes());
    }

    public function testNewBoardsGetTheConfiguredEmojis(): void
    {
        $this->boot(['board' => ['default_columns' => self::BEACON_COLUMNS]]);
        $creator = self::getContainer()->get(TaskBoardCreator::class);
        self::assertInstanceOf(TaskBoardCreator::class, $creator);

        $board = $creator->create(new TaskBoardFormData(name: 'Beacon'), $this->owner);

        self::assertSame(
            [['To do', '📝'], ['In progress', '🚧'], ['Review', '👀'], ['Done', '✅']],
            array_map(static fn (BoardColumn $column): array => [$column->getName(), $column->getIcon()], $board->getColumns()->toArray()),
        );
    }

    public function testTheCommandFillsOnlyColumnsWithoutAnIconPerTenantAndIsIdempotent(): void
    {
        $this->boot(['board' => ['default_columns' => self::BEACON_COLUMNS]]);
        $a = $this->board('Alpha', ['To do', 'In progress', 'QA', 'Done'], tenantKey: 'tenant-a');
        $a->getColumns()->get(1)?->setIcon('tabler:clock');
        $b        = $this->board('Beta', ['To do', 'Done'], tenantKey: 'tenant-b');
        $personal = $this->board('Mine', ['to do', 'Done']);
        $this->em->flush();

        $dry = $this->tester();
        self::assertSame(Command::SUCCESS, $dry->execute(['--dry-run' => true]));
        self::assertStringContainsString('Dry run: 5 column(s) on 3 of 3 board(s) would get an icon. Nothing was changed.', $this->display($dry));
        self::assertSame([null, 'tabler:clock', null, null], $this->icons($a));
        self::assertSame([], $this->writes());

        $run = $this->tester();
        self::assertSame(Command::SUCCESS, $run->execute(['--tenant' => 'tenant-a']));
        self::assertStringContainsString('2 column(s) on 1 of 1 board(s) got an icon.', $this->display($run));
        self::assertStringContainsString('tenant-a', $run->getDisplay());
        self::assertSame(['📝', 'tabler:clock', null, '✅'], $this->icons($a), 'an icon is never replaced; QA matches no default column');
        self::assertSame([null, null], $this->icons($b), 'another tenant is not touched');
        self::assertSame(['column_update', 'column_update'], $this->writes());

        $again = $this->tester();
        self::assertSame(Command::SUCCESS, $again->execute(['--tenant' => 'tenant-a']));
        self::assertStringContainsString('0 column(s) on 0 of 1 board(s) got an icon.', $this->display($again));

        $wrongTenant = $this->tester();
        self::assertSame(Command::FAILURE, $wrongTenant->execute(['--board' => $b->getId(), '--tenant' => 'tenant-a']));
        self::assertStringContainsString('Board not found.', $wrongTenant->getDisplay());
        self::assertSame([null, null], $this->icons($b));

        $one = $this->tester();
        self::assertSame(Command::SUCCESS, $one->execute(['--board' => $b->getId()]));
        self::assertSame(['📝', '✅'], $this->icons($b));

        $all = $this->tester();
        self::assertSame(Command::SUCCESS, $all->execute([]));
        self::assertSame([null, '✅'], $this->icons($personal), 'the name matches exactly ("to do" is not "To do")');

        $missing = $this->tester();
        self::assertSame(Command::FAILURE, $missing->execute(['--board' => 'nothing']));

        // Audit JM-I1: an empty --tenant is refused, not read as every tenant.
        $empty = $this->tester();
        self::assertSame(Command::INVALID, $empty->execute(['--tenant' => '']));
        self::assertStringContainsString('--tenant is empty', $this->display($empty));
        self::assertSame(Command::INVALID, $this->tester()->execute(['--tenant' => '  ']));
    }

    /** Audit JM-I2: --board skips an archived board unless --include-archived is given. */
    public function testTheCommandSkipsAnArchivedBoardUnlessAskedFor(): void
    {
        $this->boot(['board' => ['default_columns' => self::BEACON_COLUMNS]]);
        $board = $this->board('Old', ['To do', 'Done'], tenantKey: 'tenant-a');
        $board->archive();
        $this->em->flush();

        $skip = $this->tester();
        self::assertSame(Command::SUCCESS, $skip->execute(['--board' => $board->getId()]));
        self::assertStringContainsString('The board is archived: nothing was changed', $this->display($skip));
        self::assertSame([null, null], $this->icons($board));
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--tenant' => 'tenant-a']));
        self::assertSame([null, null], $this->icons($board), 'a tenant run skips it too');

        $dry = $this->tester();
        self::assertSame(Command::SUCCESS, $dry->execute(['--board' => $board->getId(), '--include-archived' => true, '--dry-run' => true]));
        self::assertSame([null, null], $this->icons($board));
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--board' => $board->getId(), '--tenant' => 'tenant-a', '--include-archived' => true]));
        self::assertSame(['📝', '✅'], $this->icons($board));
    }

    public function testTheCommandHasNothingToDoWithoutConfiguredIcons(): void
    {
        $board  = $this->board();
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No board.default_columns entry has an icon', $this->display($tester));
        self::assertSame([null, null, null], $this->icons($board));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): void
    {
        self::$taskBoardConfig = $config;
        self::ensureKernelShutdown();
        $this->setUp();
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields): void
    {
        $this->client->request('POST', self::BASE . $path, ['board_column_form' => $fields + ['_token' => PredictableCsrfTokenManager::value('board_column_form')]]);
    }

    private function column(string $name): BoardColumn
    {
        $this->em->clear();
        $column = $this->em->getRepository(BoardColumn::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(BoardColumn::class, $column);

        return $column;
    }

    /** @return list<?string> */
    private function icons(TaskBoard $board): array
    {
        $this->em->clear();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        $columns = $fresh->getColumns()->toArray();
        usort($columns, static fn (BoardColumn $x, BoardColumn $y): int => $x->getPosition() <=> $y->getPosition());

        return array_map(static fn (BoardColumn $column): ?string => $column->getIcon(), $columns);
    }

    /** @return array<string, list<string>> */
    private function flashes(): array
    {
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $flashes = $session->getFlashBag()->all();
        $session->save();

        return $flashes;
    }

    /** @return list<string> */
    private function writes(): array
    {
        $writes                  = array_values(array_filter($this->logger()->records, static fn (array $record): bool => $record['message'] === 'TaskBoard write: {action}'));
        $this->logger()->records = [];

        return array_map(static fn (array $record): string => (string) $record['context']['action'], $writes);
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find('nowo:task-board:apply-default-column-icons'));
    }
}
