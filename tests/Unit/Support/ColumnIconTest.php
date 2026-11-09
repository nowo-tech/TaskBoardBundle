<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\ColumnSetRepositoryInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Service\DefaultColumnIconFiller;
use Nowo\TaskBoardBundle\Support\ColumnIcon;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Validation\TaskConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Validator\Validation;

use function array_column;
use function array_map;
use function str_repeat;

/**
 * Since 2.9.0: a column icon is one emoji or an icon id, on every write path.
 */
final class ColumnIconTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool}> value, valid with the known ids, storable
     */
    public static function icons(): iterable
    {
        yield 'emoji' => ['📝', true, true];
        yield 'emoji with a variation selector' => ['✅', true, true];
        yield 'ZWJ sequence' => ["\u{1F9D1}\u{200D}\u{1F4BB}", true, true];
        yield 'flag' => ["\u{1F1EA}\u{1F1F8}", true, true];
        yield 'known id' => ['tabler:bug', true, true];
        yield 'unknown id' => ['tabler:x', false, true];
        yield 'bare name of a stored value' => ['bug', false, true];
        yield 'two emojis' => ['👀👀', false, false];
        yield 'emoji and text' => ['👀 Review', false, false];
        yield 'text' => ['Review', false, false];
        yield 'markup' => ['<b>x</b>', false, false];
        yield 'attribute breakout' => ['x" onload="1', false, false];
        yield 'uppercase id' => ['Tabler:Bug', false, false];
        yield 'family over 16 bytes' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}", false, false];
        yield 'long id' => ['tabler:' . str_repeat('a', 60), false, false];
    }

    #[DataProvider('icons')]
    public function testWhatAColumnIconMayBe(string $icon, bool $valid, bool $storable): void
    {
        self::assertSame($valid, ColumnIcon::isValid($icon, ['tabler:bug']));
        self::assertSame($storable, ColumnIcon::isStorable($icon));
        self::assertSame($storable ? $icon : null, ColumnIcon::normalize(' ' . $icon . ' '));

        $violations = Validation::createValidator()->validate($icon, TaskConstraints::columnIcon(['tabler:bug']));
        self::assertCount($valid ? 0 : 1, $violations);
        if (!$valid) {
            self::assertSame('A column icon is one emoji or one of the offered icons.', $violations[0]?->getMessage());
        }
    }

    public function testEmptyIsNoIconAndAnyIdIsValidWithoutAList(): void
    {
        self::assertNull(ColumnIcon::normalize(null));
        self::assertNull(ColumnIcon::normalize('  '));
        self::assertTrue(ColumnIcon::isValid('lucide:bug'));
        self::assertTrue(ColumnIcon::isId('lucide:bug'));
        self::assertFalse(ColumnIcon::isEmoji('lucide:bug'));

        $validator = Validation::createValidator();
        self::assertCount(0, $validator->validate(null, TaskConstraints::columnIcon([])));
        self::assertCount(0, $validator->validate('', TaskConstraints::columnIcon([])));
        self::assertCount(1, $validator->validate(['📝'], TaskConstraints::columnIcon([], 'custom')));
        self::assertSame('custom', $validator->validate(['📝'], TaskConstraints::columnIcon([], 'custom'))[0]?->getMessage());
    }

    public function testDefaultColumnsTakeAnEmojiAndTheExtensionKnowsTheIds(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [['user_class' => User::class, 'board' => ['default_columns' => [
            ['name' => 'To do', 'icon' => '📝'],
            ['name' => 'Bugs', 'icon' => 'tabler:bug'],
            ['name' => 'Legacy', 'icon' => 'bug'],
        ]]]]);
        self::assertSame(['📝', 'tabler:bug', 'bug'], array_column($config['board']['default_columns'], 'icon'));
        self::assertSame(['To do', 'Bugs', 'Legacy'], array_column($config['board']['default_columns'], 'name'), 'the emoji is not in the name');
        self::assertNull($config['board']['column_icon_form_type']);
        self::assertSame([], $config['board']['column_icon_form_options']);

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'board' => [
            'column_icons'             => ['tabler:bug', 'tabler:x'],
            'default_columns'          => [['name' => 'To do', 'icon' => '📝'], ['name' => 'Bugs', 'icon' => 'tabler:bug'], ['name' => 'New', 'icon' => 'lucide:star'], ['name' => 'Plain']],
            'column_icon_form_type'    => 'App\\Form\\EmojiType',
            'column_icon_form_options' => ['max_bytes' => 16],
        ]]], $container);
        self::assertSame(['tabler:bug', 'tabler:x', 'lucide:star'], $container->getParameter('nowo_task_board.board.column_icon_ids'));
        self::assertSame([
            'icon_form_type'    => 'App\\Form\\EmojiType',
            'icon_form_options' => ['max_bytes' => 16],
            'icon_known_ids'    => ['tabler:bug', 'tabler:x', 'lucide:star'],
        ], $container->getParameter('nowo_task_board.board.column_icon_form'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function badDefaultIcons(): iterable
    {
        yield 'text' => ['To do'];
        yield 'markup' => ['<img src=x>'];
        yield 'two emojis' => ['📝📝'];
        yield 'not a string' => [['📝']];
    }

    #[DataProvider('badDefaultIcons')]
    public function testDefaultColumnsRefuseAnythingElse(mixed $icon): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class, 'board' => ['default_columns' => [['name' => 'To do', 'icon' => $icon]]]]]);
    }

    public function testTheManagersNeverStoreAnInvalidIcon(): void
    {
        $board   = new TaskBoard('Roadmap', 'roadmap', new TestUser('1', 'a@example.com'));
        $columns = new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class));

        self::assertSame('🚧', $columns->add($board, new BoardColumnFormData('Doing', null, ' 🚧 '))->getIcon());
        $column = $columns->add($board, new BoardColumnFormData('Review', null, '<b>x</b>'));
        self::assertNull($column->getIcon());
        $columns->update($column, new BoardColumnFormData('Review', null, 'tabler:eye'));
        self::assertSame('tabler:eye', $column->getIcon());
        $columns->update($column, new BoardColumnFormData('Review', null, 'Review'));
        self::assertNull($column->getIcon());

        $set   = (new ColumnSet('Kanban', new TestUser('1', 'a@example.com')))->addItem('Done', null, '✅')->addItem('Bad', null, '<i>');
        $sets  = new ColumnSetManager($this->createMock(ColumnSetRepositoryInterface::class), $this->createMock(BoardColumnRepositoryInterface::class));
        $added = $sets->applyToBoard($set, $board);
        self::assertSame(['✅', null], array_map(static fn (BoardColumn $c): ?string => $c->getIcon(), $added));
    }

    public function testTheFillerMatchesByExactNameAndFillsOnlyEmptyIcons(): void
    {
        $board = new TaskBoard('Roadmap', 'roadmap', new TestUser('1', 'a@example.com'));
        $todo  = new BoardColumn($board, 'To do', 0, null, '');
        $done  = new BoardColumn($board, 'Done', 1);
        $board->addColumn($todo)->addColumn($done)->addColumn(new BoardColumn($board, 'Doing', 2, null, 'tabler:clock'));

        $repository = $this->createMock(BoardColumnRepositoryInterface::class);
        $repository->expects(self::once())->method('saveAll')->with([$todo, $done]);
        $filler = new DefaultColumnIconFiller($repository, [
            ['name' => 'To do', 'icon' => '📝'],
            ['name' => 'To do', 'icon' => '🗒️'],
            ['name' => 'Done', 'icon' => '✅'],
            ['name' => 'Doing', 'icon' => '🚧'],
            ['name' => 'Bad', 'icon' => '<b>'],
            ['name' => 'Plain'],
        ]);

        self::assertTrue($filler->hasIcons());
        $preview = $filler->fill($board, true);
        self::assertSame([[$todo, '📝'], [$done, '✅']], array_map(static fn (array $c): array => [$c['column'], $c['icon']], $preview));
        self::assertSame('', $todo->getIcon(), 'a dry run changes nothing');

        $filler->fill($board);
        self::assertSame(['📝', '✅'], [$todo->getIcon(), $done->getIcon()]);
        self::assertSame([], $filler->fill($board), 'a second run has nothing left');

        self::assertFalse((new DefaultColumnIconFiller($repository))->hasIcons(), 'the bundle defaults have no icons');
    }
}
