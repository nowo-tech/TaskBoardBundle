<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Field;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldPersonalData;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_column;
use function array_keys;
use function array_map;
use function array_unique;
use function iterator_to_array;
use function range;
use function str_repeat;
use function usort;

/**
 * Since 3.3.0 (CU-3, REQ-RUNTIME-003): field definitions carry a version (an edit or delete from an older one is
 * refused, nothing written), an import's provenance marker (one field per board and marker, found again by it) and the
 * provider's option map (renames keep the option, an option deleted here is not recreated); and the access / erasure of
 * native values for data-subject requests.
 *
 * The two-connection cases run on MySQL 8.4 / 9, MariaDB 11.4 and PostgreSQL 16 (TASK_BOARD_TEST_DATABASE_URL).
 */
#[Group('database')]
final class CustomFieldDefinitionTest extends FunctionalTestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
        $this->board = $this->board('Fields');
    }

    public function testAnEditFromAnOlderVersionIsRefusedAndWritesNothing(): void
    {
        $fields = $this->fields();
        $field  = $fields->define($this->owner, $this->board, null, 'Stage', CustomFieldType::Dropdown, [['name' => 'a'], ['name' => 'b']]);
        self::assertSame(1, $field->getVersion());

        $field = $fields->update($this->owner, $field, 'Stage 2', null, 0, 1);
        self::assertSame(2, $field->getVersion());
        $this->refused(fn (): TaskCustomField => $fields->update($this->owner, $field, 'Stale', null, 0, 1), TaskCustomFieldRefusedException::CHANGED);
        $this->refused(fn () => $fields->delete($this->owner, $field, 1), TaskCustomFieldRefusedException::CHANGED);
        $this->refused(fn (): TaskCustomField => $fields->update($this->owner, $field, str_repeat('x', TaskCustomField::NAME_MAX + 1), null, 0), TaskCustomFieldRefusedException::INVALID);
        $this->refused(fn (): TaskCustomField => $fields->update($this->owner, $field, 'Stage', [['name' => 'a'], ['name' => 'a']], 0), TaskCustomFieldRefusedException::INVALID);
        $this->em->clear();
        $stored = $fields->find($field->getId());
        self::assertSame(['Stage 2', 2], [$stored?->getName(), $stored?->getVersion()]);

        // Without a version (hosts, board configuration) the edit still raises it.
        $stored = $fields->update($this->owner, $stored ?? $field, 'Stage 3', null, 0);
        self::assertSame(3, $stored->getVersion());
        $fields->delete($this->owner, $stored, 3);
        self::assertNull($fields->find($field->getId()));
        // A definition deleted meanwhile is not found.
        $this->refused(fn (): TaskCustomField => $fields->update($this->owner, $stored, 'Gone', null, 0), TaskCustomFieldRefusedException::NOT_FOUND);
    }

    public function testTwoConnectionsEditingOneDefinition(): void
    {
        $other  = $this->secondConnection();
        $fields = $this->fields();
        $field  = $fields->define($this->owner, $this->board, null, 'Owner', CustomFieldType::Text);

        // Connection 2 saves first (its form showed version 1, as this one's).
        $other->executeStatement('UPDATE task_board_custom_fields SET name = ?, version = version + 1 WHERE id = ? AND version = 1', ['Owner (theirs)', $field->getId()]);
        $this->refused(fn (): TaskCustomField => $fields->update($this->owner, $field, 'Owner (mine)', null, 0, 1), TaskCustomFieldRefusedException::CHANGED);
        self::assertSame('Owner (theirs)', $other->fetchOne('SELECT name FROM task_board_custom_fields WHERE id = ?', [$field->getId()]));

        // One field per board and marker, whoever inserts it.
        $marked = $fields->defineFromSource($this->owner, $this->freshBoard(), 'clickup:f-1', 'From ClickUp', CustomFieldType::Text);
        self::assertInstanceOf(TaskCustomField::class, $marked);
        try {
            $other->executeStatement("INSERT INTO task_board_custom_fields (id, created_at, name, options, field_type, tenant_key, board_id, position, source_ref, source_options, version) VALUES ('00000000-0000-4000-8000-000000000001', '2026-11-26 10:00:00', 'Copy', '[]', 'text', NULL, ?, 9, 'clickup:f-1', NULL, 1)", [$this->board->getId()]);
            self::fail('a second field of the marker');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $other->fetchOne("SELECT COUNT(*) FROM task_board_custom_fields WHERE source_ref = 'clickup:f-1'"));
        }
        $other->close();
    }

    public function testAnImportedFieldIsFoundAgainByItsMarker(): void
    {
        $fields = $this->fields();
        $fields->define($this->owner, $this->board, null, 'Budget', CustomFieldType::Number);
        $created = $fields->defineFromSource($this->owner, $this->freshBoard(), 'clickup:budget', 'Budget', CustomFieldType::Number);
        self::assertInstanceOf(TaskCustomField::class, $created);
        self::assertSame(['Budget (2)', 'clickup:budget', 1], [$created->getName(), $created->getSourceRef(), $created->getPosition()]);
        // Renamed here: still the same field.
        $fields->update($this->owner, $created, 'Presupuesto', null, 1);
        $again = $fields->defineFromSource($this->owner, $this->freshBoard(), 'clickup:budget', 'Budget', CustomFieldType::Number);
        self::assertSame($created->getId(), $again?->getId());
        self::assertSame($created->getId(), $fields->sourceField($this->freshBoard(), 'clickup:budget')?->getId());
        self::assertNull($fields->sourceField($this->board('Other'), 'clickup:budget'), 'a marker is per board');
        // Retyped at the source: not followed.
        self::assertNull($fields->defineFromSource($this->owner, $this->freshBoard(), 'clickup:budget', 'Budget', CustomFieldType::Text));
        // A name of spaces takes the marker; a marker is checked; the right is checked.
        self::assertSame('clickup:blank', $fields->defineFromSource($this->owner, $this->freshBoard(), 'clickup:blank', '  ', CustomFieldType::Text)?->getName());
        $this->refused(fn (): ?\Nowo\TaskBoardBundle\Entity\TaskCustomField => $fields->defineFromSource($this->owner, $this->freshBoard(), 'not a marker', 'X', CustomFieldType::Text), TaskCustomFieldRefusedException::INVALID);
        $stranger = $this->user('stranger@example.com');
        $this->refused(fn (): ?\Nowo\TaskBoardBundle\Entity\TaskCustomField => $fields->defineFromSource($stranger, $this->freshBoard(), 'clickup:new', 'X', CustomFieldType::Text), TaskCustomFieldRefusedException::NOT_ALLOWED);
        // A field defined here keeps no marker; a marker is set once.
        $plain = $fields->define($this->owner, $this->freshBoard(), null, 'Plain', CustomFieldType::Text);
        self::assertNull($plain->getSourceRef());
        self::assertSame('clickup:budget', $created->markSource('clickup:other')->getSourceRef());
        $this->expectException(InvalidArgumentException::class);
        $plain->markSource('no marker');
    }

    public function testProviderOptionsKeepTheirOptionAndAreNotRecreated(): void
    {
        $fields = $this->fields();
        $stage  = $fields->defineFromSource($this->owner, $this->board, 'clickup:stage', 'Stage', CustomFieldType::Dropdown);
        self::assertInstanceOf(TaskCustomField::class, $stage);
        $map   = $fields->adoptSourceOptions($stage, [['id' => 'o1', 'name' => 'Draft', 'color' => '#ff0000'], ['id' => 'o2', 'name' => 'Live', 'color' => 'red']]);
        $stage = $fields->find($stage->getId());
        self::assertInstanceOf(TaskCustomField::class, $stage);
        self::assertSame([['Draft', '#ff0000'], ['Live', null]], array_map(static fn (array $o): array => [$o['name'], $o['color']], $stage->getOptions()), 'a colour the field refuses is left out');
        self::assertSame([$stage->optionNamed('Draft')['id'] ?? null, $stage->optionNamed('Live')['id'] ?? null], [$map['o1'], $map['o2']]);
        self::assertSame(2, $stage->getVersion());

        // Renamed in ClickUp and here; Draft deleted here; a new ClickUp option matches one of the same name.
        $live = $stage->optionNamed('Live')['id'] ?? '';
        $fields->update($this->owner, $stage, 'Stage', [['id' => $live, 'name' => 'Published'], ['name' => 'Archived']], 0);
        $stage = $fields->find($stage->getId());
        self::assertInstanceOf(TaskCustomField::class, $stage);
        $map = $fields->adoptSourceOptions($stage, [['id' => 'o1', 'name' => 'Draft', 'color' => null], ['id' => 'o2', 'name' => 'Go live', 'color' => null], ['id' => 'o3', 'name' => 'Archived', 'color' => null], ['id' => 'o4', 'name' => ' ', 'color' => null]]);
        self::assertSame([null, $live, $stage->optionNamed('Archived')['id'] ?? 'x', null], [$map['o1'], $map['o2'], $map['o3'], $map['o4']]);
        self::assertSame(['Published', 'Archived'], array_column($fields->find($stage->getId())?->getOptions() ?? [], 'name'), 'nothing recreated');
        $version = $fields->find($stage->getId())?->getVersion();
        $fields->adoptSourceOptions($stage, [['id' => 'o2', 'name' => 'Go live', 'color' => null]]);
        self::assertSame($version, $fields->find($stage->getId())?->getVersion(), 'nothing new: the version stays');

        // A full field takes no more options; a text field has none.
        $full = $fields->define($this->owner, $this->board, null, 'Full', CustomFieldType::Dropdown, array_map(static fn (int $n): array => ['name' => 'n' . $n], range(1, TaskCustomField::OPTIONS_MAX)));
        self::assertSame(['x' => null], $fields->adoptSourceOptions($full, [['id' => 'x', 'name' => 'More', 'color' => null]]));
        $text = $fields->define($this->owner, $this->board, null, 'Text', CustomFieldType::Text);
        self::assertSame([], $fields->adoptSourceOptions($text, [['id' => 'x', 'name' => 'More', 'color' => null]]));
        self::assertSame([], $fields->adoptSourceOptions($stage, []));
        self::assertSame(['o1', 'o2', 'o3'], array_keys($fields->find($stage->getId())?->getSourceOptions() ?? []));
    }

    public function testNativeValuesForDataSubjectRequests(): void
    {
        $fields = $this->fields();
        $note   = $fields->define($this->owner, $this->board, null, 'Contact', CustomFieldType::Text);
        $points = $fields->define($this->owner, $this->board, null, 'Points', CustomFieldType::Number);
        $stage  = $fields->define($this->owner, $this->board, null, 'Stage', CustomFieldType::Dropdown, [['name' => 'Live']]);
        $other  = $this->board('Elsewhere');
        $there  = $fields->define($this->owner, $other, null, 'Contact', CustomFieldType::Text);
        $task   = $this->task($this->freshBoard(), 'Call');
        $fields->setField($task, $note->getId(), 'Call Ana García (ana@example.com)', $this->owner);
        $fields->setField($task, $points->getId(), '2.50', $this->owner);
        $fields->setField($task, $stage->getId(), 'Live', $this->owner);
        $fields->setField($this->task($other, 'Elsewhere'), $there->getId(), 'ANA@example.com', $this->owner);

        $data  = $this->personalData();
        $board = iterator_to_array($data->exportBoard($this->freshBoard()), false);
        self::assertSame(['Call Ana García (ana@example.com)', '2.5', 'Live'], array_column($this->sorted($board), 'value'));
        self::assertSame([null], array_unique(array_column($board, 'source')));
        self::assertCount(2, iterator_to_array($data->exportByValue('ana@EXAMPLE', null), false), 'every board, case-insensitive');
        self::assertCount(1, iterator_to_array($data->exportByValue('ana@example', $this->freshBoard()), false));
        self::assertSame([], iterator_to_array($data->exportByValue('an', null), false), 'too short');
        self::assertSame([], iterator_to_array($data->exportByValue('50%', null), false), 'LIKE wildcards are literal');
        self::assertSame(0, $data->eraseByValue('Li', null));
        self::assertSame(1, $data->eraseByValue('ana@example.com', $this->freshBoard()));
        self::assertSame(['2.5', 'Live'], array_column($this->sorted(iterator_to_array($data->exportBoard($this->freshBoard()), false)), 'value'));
        // A drop-down value whose option is gone reads as empty.
        $this->em->createQuery('UPDATE ' . TaskCustomFieldValue::class . ' v SET v.textValue = :gone WHERE v.field = :field')->setParameter('gone', 'gone')->setParameter('field', $stage->getId())->execute();
        self::assertSame(['2.5', ''], array_column($this->sorted(iterator_to_array($data->exportBoard($this->freshBoard()), false)), 'value'));
        self::assertCount(1, iterator_to_array($data->exportByValue('ana@example', null), false), 'the other board keeps its value');
    }

    /**
     * @param list<array{field: string, value: string}> $rows
     *
     * @return list<array{field: string, value: string}>
     */
    private function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $a['field'] <=> $b['field']);

        return $rows;
    }

    private function refused(callable $call, string $reason): void
    {
        try {
            $call();
            self::fail('expected ' . $reason);
        } catch (TaskCustomFieldRefusedException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function freshBoard(): TaskBoard
    {
        $board = $this->em->find(TaskBoard::class, $this->board->getId());
        self::assertInstanceOf(TaskBoard::class, $board);

        return $board;
    }

    private function secondConnection(): Connection
    {
        $main = $this->em->getConnection();
        if ($main->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('One writer at a time: there is no second connection to race with.');
        }

        return DriverManager::getConnection($main->getParams());
    }

    private function fields(): TaskCustomFieldManager
    {
        $service = self::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }

    private function personalData(): TaskCustomFieldPersonalData
    {
        $service = self::getContainer()->get(TaskCustomFieldPersonalData::class);
        self::assertInstanceOf(TaskCustomFieldPersonalData::class, $service);

        return $service;
    }
}
