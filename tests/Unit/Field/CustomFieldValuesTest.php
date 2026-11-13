<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Field;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTaskFilter;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use PHPUnit\Framework\TestCase;
use stdClass;

use function json_encode;
use function str_repeat;

final class CustomFieldValuesTest extends TestCase
{
    public function testOperatorsAndTypes(): void
    {
        foreach (['=' => '=', 'eq' => '=', '≠' => '!=', '<>' => '!=', 'lt' => '<', 'gt' => '>', 'is_set' => 'IS NOT NULL', 'IS NULL' => 'IS NULL', 'in' => 'ANY'] as $raw => $value) {
            self::assertSame($value, CustomFieldOperator::parse($raw)?->value, $raw);
        }
        self::assertNull(CustomFieldOperator::parse('LIKE'));
        self::assertFalse(CustomFieldOperator::IsSet->needsValue());
        self::assertTrue(CustomFieldOperator::In->needsValue());
        foreach (CustomFieldType::cases() as $type) {
            self::assertSame($type, CustomFieldType::fromApi($type->apiType()));
        }
        self::assertSame(CustomFieldType::Text, CustomFieldType::fromApi('text'));
        self::assertNull(CustomFieldType::fromApi('formula'));
    }

    public function testDefinitionsValidate(): void
    {
        $board = new TaskBoard('B', 'b', new stdClass());
        $board->setTenantKey('acme');
        $field = new TaskCustomField('Capa', CustomFieldType::Dropdown, 'acme', $board, 2);
        $field->setOptions([['name' => 'domain', 'color' => '#abc'], ['name' => 'infra']]);
        $id = $field->getOptions()[0]['id'];
        $field->setOptions([['id' => $id, 'name' => 'domain'], ['id' => 'forged', 'name' => 'new']]);
        self::assertSame($id, $field->getOptions()[0]['id'], 'a known id is kept');
        self::assertNotSame('forged', $field->getOptions()[1]['id'], 'an unknown id is not taken');
        self::assertNull($field->option('nope'));
        self::assertNull($field->optionNamed('nope'));
        self::assertSame($board->getId(), $field->getBoardId());
        self::assertSame(2, $field->setPosition(2)->getPosition());

        foreach ([
            static fn () => new TaskCustomField('X', CustomFieldType::Text, 'other', $board),
            static fn () => new TaskCustomField(' ', CustomFieldType::Text),
            static fn () => (new TaskCustomField('T', CustomFieldType::Text))->setOptions([['name' => 'a']]),
            static fn () => $field->setOptions([['name' => 'a'], ['name' => 'a']]),
            static fn () => $field->setOptions([['name' => 'a', 'color' => 'red']]),
            static fn () => $field->setOptions([['color' => '#fff']]),
            static fn () => $field->setOptions(array_fill(0, TaskCustomField::OPTIONS_MAX + 1, ['name' => 'x'])),
            static fn () => $field->setName(str_repeat('x', TaskCustomField::NAME_MAX + 1)),
        ] as $index => $invalid) {
            try {
                $invalid();
                self::fail('invalid #' . $index);
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testTheApiFilterParses(): void
    {
        $conditions = TaskApiTaskFilter::customFields((string) json_encode([
            ['field_id' => 'f1', 'operator' => '=', 'value' => 3],
            ['field_id' => 'f2', 'operator' => 'ANY', 'value' => ['a', 1.5]],
            ['field_id' => 'f3', 'operator' => 'IS NULL'],
        ]));
        self::assertSame(['3'], $conditions[0]->values);
        self::assertSame(['a', '1.5'], $conditions[1]->values);
        self::assertSame([], $conditions[2]->values);
        self::assertSame([], TaskApiTaskFilter::customFields(null));
        foreach ([
            '{"a":1}', 'not json', (string) json_encode([['field_id' => 'f', 'operator' => 'LIKE', 'value' => 1]]),
            (string) json_encode([['operator' => '=', 'value' => 1]]),
            (string) json_encode([['field_id' => 'f', 'operator' => '=', 'value' => [true]]]),
            (string) json_encode([['field_id' => 'f', 'operator' => '=']]),
            (string) json_encode([['field_id' => 'f', 'operator' => '=', 'value' => [1, 2]]]),
            (string) json_encode(array_fill(0, 11, ['field_id' => 'f', 'operator' => 'IS NULL'])),
        ] as $bad) {
            try {
                TaskApiTaskFilter::customFields($bad);
                self::fail('invalid ' . $bad);
            } catch (TaskApiRefusal $refusal) {
                self::assertSame('INPUT_002', $refusal->ecode);
            }
        }
    }
}
