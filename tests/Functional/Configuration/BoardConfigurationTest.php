<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Configuration;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Configuration\BoardColumnSpec;
use Nowo\TaskBoardBundle\Configuration\BoardConfiguration;
use Nowo\TaskBoardBundle\Configuration\BoardConfigurationApplier;
use Nowo\TaskBoardBundle\Configuration\BoardConfigurationExporter;
use Nowo\TaskBoardBundle\Configuration\BoardFieldSpec;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\ColumnType;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Support\ColumnTypes;
use Nowo\TaskBoardBundle\Tests\Functional\Copy\CopyTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;

/**
 * Since 2.17.0 (Beacon block W): a board's configuration, readable and appliable as data.
 */
final class BoardConfigurationTest extends CopyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $tokens = static::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($this->owner, 'main', $this->owner->getRoles()));
    }

    public function testExportRoundTripsThroughJsonAndAppliesToAnotherBoard(): void
    {
        $preset = $this->board('Preset', ['Pendiente', 'En ejecución', 'Hecho', 'Cancelada'], tenantKey: 'acme');
        $this->columnTypes($preset, [ColumnType::Open, ColumnType::Custom, ColumnType::Closed, ColumnType::Closed]);
        $preset->setNumberPrefix('AIF');
        $this->em->flush();
        $this->fieldManager()->define($this->owner, $preset, null, 'Capa', CustomFieldType::Dropdown, [['name' => 'domain', 'color' => '#ff0000']]);
        $this->fieldManager()->define($this->owner, null, 'acme', 'Tenant wide', CustomFieldType::Text);

        $json   = $this->exporter()->export($preset)->toJson();
        $config = BoardConfiguration::fromJson($json);
        self::assertSame(['Pendiente', 'En ejecución', 'Hecho', 'Cancelada'], array_map(static fn (BoardColumnSpec $c): string => $c->name, $config->columns));
        self::assertSame(['Capa'], array_map(static fn (BoardFieldSpec $f): string => $f->name, $config->fields), 'tenant-wide fields are not the board\'s');
        self::assertSame('AIF', $config->numberPrefix);

        $target = $this->board('Story', ['To do', 'Done'], tenantKey: 'acme');
        $task   = $this->task($target, 'Kept', 1);
        $this->applier()->apply($this->owner, $target, $config, ['To do' => 'Pendiente', 'Done' => 'Hecho']);

        $this->clear();
        $fresh = $this->em->find(TaskBoard::class, $target->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        self::assertSame(['Pendiente:open', 'En ejecución:custom', 'Hecho:closed', 'Cancelada:closed'], array_map(static fn (BoardColumn $c): string => $c->getName() . ':' . ColumnTypes::of($c)->value, ColumnTypes::ordered($fresh)));
        self::assertSame('AIF', $fresh->getNumberPrefix());
        $kept = $this->reload($task);
        self::assertSame('Hecho', $kept->getColumn()?->getName());
        self::assertTrue($kept->isCompleted());
        self::assertSame($config->toArray(), $this->exporter()->export($fresh)->toArray(), 'apply then export gives the same data');
    }

    public function testApplyNeverOrphansATaskAndIsAtomic(): void
    {
        $board = $this->board('Story', ['Todo', 'Old'], tenantKey: 'acme');
        $this->task($board, 'In old', 1);
        $config = new BoardConfiguration([new BoardColumnSpec('Todo', ColumnType::Open), new BoardColumnSpec('Done', ColumnType::Closed)], [new BoardFieldSpec('Points', CustomFieldType::Number)], [], 'ST');

        try {
            $this->applier()->apply($this->owner, $board, $config);
            self::fail('Old holds a task');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::COLUMNS_IN_USE, $refused->reason);
        }
        $this->clear();
        $fresh = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $fresh);
        self::assertSame(['Todo', 'Old'], array_map(static fn (BoardColumn $c): string => $c->getName(), ColumnTypes::ordered($fresh)));
        self::assertNull($fresh->getNumberPrefix());
        self::assertSame([], $this->fieldManager()->fieldsFor($fresh), 'nothing written');
        self::assertTrue($this->em->isOpen());
    }

    public function testAFieldWithValuesIsNotDroppedSilently(): void
    {
        $board  = $this->board('Story', tenantKey: 'acme');
        $points = $this->fieldManager()->define($this->owner, $board, null, 'Points', CustomFieldType::Number);
        $task   = $this->task($board, 'Valued');
        $this->fieldManager()->setField($task, $points->getId(), '3', $this->owner);
        $config = new BoardConfiguration(\Nowo\TaskBoardBundle\Configuration\BoardColumnsApplier::specsOf($board));

        try {
            $this->applier()->apply($this->owner, $board, $config);
            self::fail('Points holds a value');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::FIELDS_IN_USE, $refused->reason);
        }
        $this->applier()->apply($this->owner, $board, $config, [], dropFieldValues: true);
        self::assertSame([], $this->fieldManager()->fieldsFor($board));
    }

    public function testTenantAndRightAreChecked(): void
    {
        $board    = $this->board('Theirs', tenantKey: 'other');
        $config   = $this->exporter()->export($board);
        $stranger = $this->user('stranger@example.com');

        self::assertNull($this->exporter()->exportById($board->getId(), 'acme'));
        try {
            $this->applier()->applyById($this->owner, $board->getId(), 'acme', $config);
            self::fail('another tenant');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::NOT_FOUND, $refused->reason);
        }
        try {
            $this->applier()->apply($stranger, $board, $config);
            self::fail('no manage right');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::NOT_ALLOWED, $refused->reason);
        }
    }

    public function testInvalidDataIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BoardConfiguration::fromArray(['version' => 99, 'columns' => []]);
    }

    /**
     * @param list<ColumnType> $types
     */
    private function columnTypes(TaskBoard $board, array $types): void
    {
        foreach (ColumnTypes::ordered($board) as $index => $column) {
            $column->setType($types[$index]);
        }
    }

    private function exporter(): BoardConfigurationExporter
    {
        $service = static::getContainer()->get(BoardConfigurationExporter::class);
        self::assertInstanceOf(BoardConfigurationExporter::class, $service);

        return $service;
    }

    private function applier(): BoardConfigurationApplier
    {
        $service = static::getContainer()->get(BoardConfigurationApplier::class);
        self::assertInstanceOf(BoardConfigurationApplier::class, $service);

        return $service;
    }

    private function fieldManager(): TaskCustomFieldManager
    {
        $service = static::getContainer()->get(TaskCustomFieldManager::class);
        self::assertInstanceOf(TaskCustomFieldManager::class, $service);

        return $service;
    }
}
