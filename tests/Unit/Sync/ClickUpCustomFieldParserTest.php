<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpCustomFieldParser;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use PHPUnit\Framework\TestCase;

use function array_map;
use function range;
use function str_repeat;
use function strlen;

/**
 * Since 1.27.0 (TB-SYNC-3): ClickUp custom fields as typed values — every known type, values that do not fit (kept as
 * text), unknown types (text), and untrusted input (ids, names, URLs, e-mails, numbers, colours, sizes).
 */
final class ClickUpCustomFieldParserTest extends TestCase
{
    public function testEveryKnownType(): void
    {
        $fields = $this->byName((new ClickUpCustomFieldParser())->parse([
            ['id' => 'f1', 'name' => 'Notes', 'type' => 'text', 'value' => "multi\nline"],
            ['id' => 'f2', 'name' => 'Short', 'type' => 'short_text', 'value' => 'abc'],
            ['id' => 'f3', 'name' => 'Count', 'type' => 'number', 'value' => '12.50'],
            ['id' => 'f4', 'name' => 'Budget', 'type' => 'currency', 'type_config' => ['currency_type' => 'eur'], 'value' => 1500],
            ['id' => 'f5', 'name' => 'Deadline', 'type' => 'date', 'value' => '1790000000000'],
            ['id' => 'f6', 'name' => 'Meeting', 'type' => 'date', 'type_config' => ['time' => true], 'value' => 1790000000000],
            ['id' => 'f7', 'name' => 'Stage', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'o1', 'name' => 'Draft', 'color' => '#ff0000'], ['id' => 'o2', 'name' => 'Live', 'color' => 'red;x']]], 'value' => 1],
            ['id' => 'f8', 'name' => 'Stage id', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'o1', 'name' => 'Draft']]], 'value' => 'o1'],
            ['id' => 'f9', 'name' => 'Areas', 'type' => 'labels', 'type_config' => ['options' => [['id' => 'l1', 'label' => 'Web'], ['id' => 'l2', 'label' => 'App'], ['id' => 'bad id!', 'label' => 'X'], 'junk']], 'value' => ['l2', 'l1', 'zz']],
            ['id' => 'f10', 'name' => 'Done', 'type' => 'checkbox', 'value' => 'true'],
            ['id' => 'f11', 'name' => 'Off', 'type' => 'checkbox', 'value' => false],
            ['id' => 'f12', 'name' => 'Site', 'type' => 'url', 'value' => 'https://example.com/a?b=1'],
            ['id' => 'f13', 'name' => 'Mail', 'type' => 'email', 'value' => 'ana@example.com'],
            ['id' => 'f14', 'name' => 'Phone', 'type' => 'phone', 'value' => '+34 600 000 000'],
            ['id' => 'f15', 'name' => 'Stars', 'type' => 'emoji', 'type_config' => ['count' => 3], 'value' => 7],
            ['id' => 'f16', 'name' => 'Auto', 'type' => 'automatic_progress', 'value' => ['percent_completed' => 140]],
            ['id' => 'f17', 'name' => 'Manual', 'type' => 'manual_progress', 'value' => 40],
            ['id' => 'f18', 'name' => 'Owners', 'type' => 'users', 'value' => [['id' => 1, 'username' => 'Ana'], ['id' => 1, 'username' => 'Dup'], ['id' => 2], ['id' => 'bad id!'], 'junk']],
            ['id' => 'f19', 'name' => 'Related', 'type' => 'tasks', 'value' => [['id' => 't1', 'name' => 'Other task']]],
            ['id' => 'f20', 'name' => 'Formula', 'type' => 'formula', 'value' => ['x' => 1]],
            ['id' => 'f21', 'name' => 'Empty', 'type' => 'text', 'value' => null],
            ['id' => 'f22', 'name' => 'Default stars', 'type' => 'emoji', 'value' => 2],
        ]));

        self::assertSame(['multi line', 'multi line'], [$fields['Notes']->value, $fields['Notes']->text]);
        self::assertSame(SyncFieldType::Text, $fields['Short']->type);
        self::assertSame(['12.5', '12.5'], [$fields['Count']->value, $fields['Count']->text]);
        self::assertSame(['1500', '1500 EUR', ['currency' => 'EUR']], [$fields['Budget']->value, $fields['Budget']->text, $fields['Budget']->config]);
        self::assertSame(['2026-09-21T14:13:20+00:00', '2026-09-21'], [$fields['Deadline']->value, $fields['Deadline']->text]);
        self::assertSame('2026-09-21 14:13', $fields['Meeting']->text);
        self::assertSame(['o2', 'Live'], [$fields['Stage']->value, $fields['Stage']->text]);
        self::assertNull($fields['Stage']->choices[1]['color'], 'a colour that is not a hex value is dropped');
        self::assertSame('#ff0000', $fields['Stage']->choices[0]['color']);
        self::assertSame('o1', $fields['Stage id']->value);
        self::assertSame([['l1', 'l2'], 'Web, App'], [$fields['Areas']->value, $fields['Areas']->text]);
        self::assertCount(2, $fields['Areas']->choices);
        self::assertTrue($fields['Done']->value);
        self::assertFalse($fields['Off']->value);
        self::assertTrue($fields['Off']->hasValue());
        self::assertSame('https://example.com/a?b=1', $fields['Site']->value);
        self::assertSame(SyncFieldType::Email, $fields['Mail']->type);
        self::assertSame('+34 600 000 000', $fields['Phone']->value);
        self::assertSame([3, '3/3'], [$fields['Stars']->value, $fields['Stars']->text]);
        self::assertSame([2, '2/5'], [$fields['Default stars']->value, $fields['Default stars']->text]);
        self::assertSame([100, '100%'], [$fields['Auto']->value, $fields['Auto']->text]);
        self::assertSame(40, $fields['Manual']->value);
        self::assertSame([[['id' => '1', 'name' => 'Ana'], ['id' => '2', 'name' => '2']], 'Ana, 2'], [$fields['Owners']->value, $fields['Owners']->text]);
        self::assertSame(SyncFieldType::Relationship, $fields['Related']->type);
        self::assertSame([SyncFieldType::Text, 'formula', '{"x":1}'], [$fields['Formula']->type, $fields['Formula']->remoteType, $fields['Formula']->value]);
        self::assertFalse($fields['Empty']->hasValue(), 'a field without value keeps its definition');
    }

    public function testValuesThatDoNotFitAreKeptAsText(): void
    {
        $fields = $this->byName((new ClickUpCustomFieldParser())->parse([
            ['id' => 'a', 'name' => 'Js link', 'type' => 'url', 'value' => 'javascript:alert(1)'],
            ['id' => 'b', 'name' => 'Ftp', 'type' => 'url', 'value' => 'ftp://example.com/x'],
            ['id' => 'c', 'name' => 'Bad mail', 'type' => 'email', 'value' => 'not mail'],
            ['id' => 'd', 'name' => 'Bad number', 'type' => 'number', 'value' => '12abc'],
            ['id' => 'e', 'name' => 'Huge', 'type' => 'number', 'value' => '1e999'],
            ['id' => 'f', 'name' => 'Bad date', 'type' => 'date', 'value' => 'soon'],
            ['id' => 'g', 'name' => 'No option', 'type' => 'drop_down', 'value' => 9],
            ['id' => 'h', 'name' => 'Odd drop', 'type' => 'drop_down', 'value' => ['x']],
            ['id' => 'i', 'name' => 'Odd labels', 'type' => 'labels', 'value' => 'l1'],
            ['id' => 'j', 'name' => 'Odd check', 'type' => 'checkbox', 'value' => 'maybe'],
            ['id' => 'k', 'name' => 'Odd phone', 'type' => 'phone', 'value' => '<b>call</b>'],
            ['id' => 'l', 'name' => 'Odd stars', 'type' => 'emoji', 'value' => -1],
            ['id' => 'm', 'name' => 'Odd progress', 'type' => 'manual_progress', 'value' => ['x' => 1]],
            ['id' => 'n', 'name' => 'Odd users', 'type' => 'users', 'value' => 'ana'],
            ['id' => 'o', 'name' => 'No users', 'type' => 'users', 'value' => [['id' => 'bad id!']]],
            ['id' => 'p', 'name' => 'Odd text', 'type' => 'text', 'value' => ['a']],
            ['id' => 'q', 'name' => 'Blank', 'type' => 'text', 'value' => "\u{202E}"],
            ['id' => 'r', 'name' => 'Bool text', 'type' => 'location', 'value' => true],
            ['id' => 's', 'name' => 'Long url', 'type' => 'url', 'value' => 'https://example.com/' . str_repeat('a', 2100)],
            ['id' => 't', 'name' => 'Float', 'type' => 'currency', 'type_config' => ['currency_type' => 'EURO'], 'value' => 2.5],
            ['id' => 'u', 'name' => 'Obj number', 'type' => 'number', 'value' => ['n' => 1]],
            ['id' => 'v', 'name' => 'Nan', 'type' => 'number', 'value' => 'NAN'],
        ]));

        foreach (['Js link', 'Ftp', 'Bad mail', 'Bad number', 'Huge', 'Bad date', 'No option', 'Odd drop', 'Odd labels', 'Odd check', 'Odd phone', 'Odd stars', 'Odd progress', 'Odd text', 'Bool text', 'Long url', 'Obj number', 'Nan'] as $name) {
            self::assertTrue($fields[$name]->fallback, $name);
        }
        // The field keeps its declared type (audit GW-L1), and a person's name never stays as text (GW-M3).
        self::assertSame(SyncFieldType::Url, $fields['Js link']->type);
        self::assertFalse($fields['Odd users']->hasValue());
        self::assertFalse($fields['No users']->hasValue());
        self::assertSame('javascript:alert(1)', $fields['Js link']->text, 'shown as text, never as a link');
        self::assertSame('true', $fields['Bool text']->text);
        self::assertFalse($fields['Blank']->hasValue());
        self::assertSame(['2.5', '2.5', []], [$fields['Float']->value, $fields['Float']->text, $fields['Float']->config]);
    }

    public function testUntrustedShapes(): void
    {
        $parser = new ClickUpCustomFieldParser();
        // Audit GW-L9, GW-L7: mailto parameters, and a people id too long for the directory.
        $mail = $parser->parse([['id' => 'e', 'name' => 'Mail', 'type' => 'email', 'value' => 'a?bcc=x&subject=y@example.test']]);
        self::assertTrue($mail[0]->fallback);
        $long = $parser->parse([['id' => 'u', 'name' => 'Owners', 'type' => 'users', 'value' => [['id' => str_repeat('1', 65), 'username' => 'Fake']]]]);
        self::assertFalse($long[0]->hasValue());
        self::assertSame(SyncFieldType::Email, $parser->typeOf('EMAIL'));
        self::assertSame([], $parser->parse('junk'));
        self::assertSame([], $parser->parse([['id' => 'bad id!', 'name' => 'X'], ['id' => 'x', 'name' => ''], 'junk', ['name' => 'No id']]));
        self::assertCount(ClickUpCustomFieldParser::MAX_FIELDS, $parser->parse(array_map(static fn (int $i): array => ['id' => 'f' . $i, 'name' => 'F' . $i], range(1, 150))));
        $many = $parser->parse([['id' => 'm', 'name' => 'Many', 'type' => 'labels', 'type_config' => ['options' => array_map(static fn (int $i): array => ['id' => 'o' . $i, 'name' => 'O' . $i], range(1, 300))], 'value' => ['o1']]]);
        self::assertCount(ClickUpCustomFieldParser::MAX_CHOICES, $many[0]->choices);
        $noName = $parser->parse([['id' => 'm', 'name' => 'Opts', 'type' => 'drop_down', 'type_config' => ['options' => [['id' => 'o1']]], 'value' => 0]]);
        self::assertSame('o1', $noName[0]->text, 'a choice without a name shows its id');
        $long = $parser->parse([['id' => 'l', 'name' => 'Long', 'type' => 'text', 'value' => str_repeat('é', 3000)]]);
        self::assertLessThanOrEqual(ClickUpCustomFieldParser::MAX_TEXT, strlen((string) $long[0]->value));
        $unknownType = $parser->parse([['id' => 'u', 'name' => 'Untyped', 'value' => 3]]);
        self::assertSame([SyncFieldType::Text, '', '3'], [$unknownType[0]->type, $unknownType[0]->remoteType, $unknownType[0]->value]);
    }

    /**
     * @param list<RemoteCustomField> $fields
     *
     * @return array<string, RemoteCustomField>
     */
    private function byName(array $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field->name] = $field;
        }

        return $byName;
    }
}
