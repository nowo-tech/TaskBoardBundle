<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Template;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Template\TaskTemplateData;
use Nowo\TaskBoardBundle\Template\TaskTemplateScope;
use Nowo\TaskBoardBundle\Template\TaskTemplateSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Beacon spec 234, FR-061 / block W: template data as arrays, round trip and refusals; the scope value object.
 */
final class TaskTemplateDataTest extends TestCase
{
    public function testARoundTripThroughAnArray(): void
    {
        $snapshot = new TaskTemplateSnapshot('id-1', 'Historia', TaskTemplateSnapshot::SCOPE_BOARD, 'board-1', 'acme', '## Contexto', ['repo:'], 'col-1', 'Doing');
        $data     = TaskTemplateData::fromArray($snapshot->toArray());

        self::assertSame(['Historia', '## Contexto', ['repo:'], 'col-1', 'Doing'], [$data->name, $data->body, $data->defaultTags, $data->defaultColumnId, $data->defaultColumnName]);

        $minimal = TaskTemplateData::fromArray(['name' => 'Only a name', 'body' => null]);
        self::assertSame([null, [], null, null], [$minimal->body, $minimal->defaultTags, $minimal->defaultColumnId, $minimal->defaultColumnName]);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalid')]
    public function testWrongTypesAreRefused(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaskTemplateData::fromArray($data);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'no name' => [[]];
        yield 'name not a string' => [['name' => 1]];
        yield 'tags not a list' => [['name' => 'x', 'default_tags' => ['a' => 'b']]];
        yield 'tags not an array' => [['name' => 'x', 'default_tags' => 'a']];
        yield 'tag not a string' => [['name' => 'x', 'default_tags' => [1]]];
        yield 'column not a string' => [['name' => 'x', 'default_column_id' => 3]];
    }

    public function testTheScope(): void
    {
        $board = new TaskBoardSnapshot('b', 'slug', 'Board', 'acme', null, null, false, new DateTimeImmutable());

        self::assertTrue(TaskTemplateScope::board($board)->isBoard());
        self::assertSame('acme', TaskTemplateScope::board($board)->tenantKey);
        self::assertFalse(TaskTemplateScope::tenant(null)->isBoard());
        self::assertNull(TaskTemplateScope::tenant(null)->tenantKey);
    }
}
