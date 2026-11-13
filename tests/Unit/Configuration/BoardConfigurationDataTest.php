<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Configuration;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Configuration\BoardConfiguration;
use Nowo\TaskBoardBundle\Configuration\BoardFieldSpec;
use Nowo\TaskBoardBundle\Configuration\NoBoardTemplates;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BoardConfigurationDataTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $config = new BoardConfiguration(
            [new BoardColumnSpec('Pendiente', ColumnType::Open), new BoardColumnSpec('Hecho', ColumnType::Closed, '#00aa00')],
            [new BoardFieldSpec('Capa', CustomFieldType::Dropdown, [['name' => 'domain', 'color' => '#ff0000']])],
            [['name' => 'AI Flow · Tarea', 'body' => '## Contexto']],
            'AIF',
        );
        self::assertEquals($config, BoardConfiguration::fromJson($config->toJson()));
        self::assertEquals($config, BoardConfiguration::fromArray($config->toArray()));
        self::assertSame(BoardConfiguration::VERSION, $config->toArray()['version']);
        self::assertSame(['name' => 'X', 'type' => 'number', 'options' => []], BoardFieldSpec::fromArray(['name' => 'X', 'type' => 'number'])->toArray());
    }

    public function testInvalidData(): void
    {
        foreach ([
            static fn () => new BoardConfiguration([]),
            static fn () => BoardConfiguration::fromArray('x'),
            static fn () => BoardConfiguration::fromArray(['version' => 2, 'columns' => [['name' => 'A']]]),
            static fn () => BoardConfiguration::fromArray(['columns' => 'A']),
            static fn () => BoardConfiguration::fromArray(['columns' => [['name' => 'A']], 'templates' => ['x']]),
            static fn () => BoardConfiguration::fromArray(['columns' => [['name' => 'A']], 'numberPrefix' => 5]),
            static fn () => BoardConfiguration::fromJson('{'),
            static fn () => BoardFieldSpec::fromArray(['name' => 'X']),
            static fn () => BoardFieldSpec::fromArray(['name' => 'X', 'type' => 'formula']),
            static fn () => BoardFieldSpec::fromArray(['name' => 'X', 'type' => 'dropdown', 'options' => 'a']),
            static fn () => BoardFieldSpec::fromArray(['name' => 'X', 'type' => 'dropdown', 'options' => [['color' => '#fff']]]),
            static fn () => new BoardFieldSpec('', CustomFieldType::Text),
            static fn () => new BoardFieldSpec('T', CustomFieldType::Text, [['name' => 'a', 'color' => null]]),
        ] as $index => $invalid) {
            try {
                $invalid();
                self::fail('invalid #' . $index);
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testNoTemplatesAndPrefix(): void
    {
        $templates = new NoBoardTemplates();
        $snapshot  = new TaskBoardSnapshot('id', 'slug', 'Name', null, null, null, false, new DateTimeImmutable());
        self::assertSame([], $templates->export($snapshot));
        $templates->apply($snapshot, [['name' => 'ignored']]);

        $board = new TaskBoard('B', 'b', new stdClass());
        self::assertSame('AIF', $board->setNumberPrefix('AIF')->getNumberPrefix());
        self::assertNull($board->setNumberPrefix(null)->getNumberPrefix());
        $this->expectException(InvalidArgumentException::class);
        $board->setNumberPrefix('aif');
    }
}
