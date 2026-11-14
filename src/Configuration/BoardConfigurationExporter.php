<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Since 2.19.0 (Beacon block W): reads a board's configuration as a {@see BoardConfiguration} — its columns (with their
 * effective types), its own custom fields, its task templates and its numbering prefix. Reads only; stateless.
 */
final readonly class BoardConfigurationExporter
{
    public function __construct(
        private TaskCustomFieldManager $fields,
        private TaskBoardRepositoryInterface $boards,
        private BoardTemplatesConfigurationInterface $templates = new NoBoardTemplates(),
    ) {
    }

    public function export(TaskBoard $board): BoardConfiguration
    {
        $own = array_values(array_filter($this->fields->fieldsFor($board), static fn (TaskCustomField $field): bool => $field->getBoardId() === $board->getId()));

        return new BoardConfiguration(
            BoardColumnsApplier::specsOf($board),
            array_map(static fn (TaskCustomField $field): BoardFieldSpec => new BoardFieldSpec(
                $field->getName(),
                $field->getType(),
                array_map(static fn (array $option): array => ['name' => $option['name'], 'color' => $option['color']], $field->getOptions()),
            ), $own),
            $this->templates->export(Snapshots::board($board)),
            $board->getNumberPrefix(),
        );
    }

    /**
     * By id, within a tenant: null when the board is unknown or of another tenant (byte for byte).
     */
    public function exportById(string $boardId, ?string $tenantKey): ?BoardConfiguration
    {
        $board = $boardId === '' ? null : $this->boards->findById($boardId);

        return $board instanceof TaskBoard && $board->getId() === $boardId && $board->getTenantKey() === $tenantKey ? $this->export($board) : null;
    }
}
