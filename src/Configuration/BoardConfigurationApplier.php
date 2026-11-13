<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskBoardFolder;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Folder\TaskBoardFolderManager;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;

use function array_map;
use function implode;

/**
 * Since 2.17.0 (Beacon block W): makes a board's configuration exactly a {@see BoardConfiguration}, atomically (one
 * transaction: everything or nothing), under {@see TaskBoardRestructurePolicyInterface::mayRestructureBoard()} (the
 * manage right by default).
 *
 * - Columns ({@see BoardColumnsApplier}): kept by name or by `$columnMap` (current column id or name => configuration
 *   column name), so their tasks stay; a column that holds tasks and is not in the configuration needs the map —
 *   `columns_in_use` otherwise. A task is never orphaned. A board whose folder shares its statuses takes them from the
 *   folder: a configuration with other columns is refused (`columns_shared`).
 * - Fields: the board's own fields become the configuration's (by name; a dropdown keeps the ids of the options whose
 *   names stay, so their values stay). Removing or retyping a field that holds values is refused (`fields_in_use`) unless
 *   `$dropFieldValues`.
 * - Templates through {@see BoardTemplatesConfigurationInterface}; the numbering prefix as given.
 *
 * Stateless.
 */
final readonly class BoardConfigurationApplier
{
    public function __construct(
        private TaskBoardFolderManager $folders,
        private TaskCustomFieldManager $fields,
        private TaskBoardRestructurePolicyInterface $policy,
        private TaskBoardRepositoryInterface $boards,
        private BoardTemplatesConfigurationInterface $templates = new NoBoardTemplates(),
    ) {
    }

    /**
     * @param array<string, string> $columnMap
     *
     * @throws TaskBoardRestructureRefusedException `not_allowed`, `columns_in_use`, `columns_shared`, `fields_in_use`,
     *                                              `invalid` (`field`: columns, fields, number_prefix), `quota`
     */
    public function apply(object $actor, TaskBoard $board, BoardConfiguration $configuration, array $columnMap = [], bool $dropFieldValues = false): void
    {
        if (!$this->policy->mayRestructureBoard($actor, Snapshots::board($board))) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_ALLOWED);
        }
        $shared = $board->getFolder() instanceof TaskBoardFolder ? $board->getFolder()->getStatusSet() : null;
        if ($shared !== null && $this->columnKeys($shared) !== $this->columnKeys($configuration->columns)) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::COLUMNS_SHARED);
        }
        $this->folders->transactional(function () use ($actor, $board, $configuration, $columnMap, $dropFieldValues, $shared): void {
            if ($shared === null) {
                $this->folders->applyColumns($board, $configuration->columns, $columnMap);
            }
            $this->applyFields($actor, $board, $configuration->fields, $dropFieldValues);
            $this->templates->apply(Snapshots::board($board), $configuration->templates);
            try {
                $board->setNumberPrefix($configuration->numberPrefix);
            } catch (InvalidArgumentException) {
                throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::INVALID, ['field' => 'number_prefix']);
            }
        });
    }

    /**
     * By id, within a tenant: another tenant's board (byte for byte) is `not_found`, as an unknown id.
     *
     * @param array<string, string> $columnMap
     *
     * @throws TaskBoardRestructureRefusedException
     */
    public function applyById(object $actor, string $boardId, ?string $tenantKey, BoardConfiguration $configuration, array $columnMap = [], bool $dropFieldValues = false): void
    {
        $board = $boardId === '' ? null : $this->boards->findById($boardId);
        if (!$board instanceof TaskBoard || $board->getId() !== $boardId || $board->getTenantKey() !== $tenantKey) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::NOT_FOUND);
        }
        $this->apply($actor, $board, $configuration, $columnMap, $dropFieldValues);
    }

    /**
     * @param list<BoardFieldSpec> $specs
     *
     * @throws TaskBoardRestructureRefusedException
     */
    private function applyFields(object $actor, TaskBoard $board, array $specs, bool $dropFieldValues): void
    {
        $own = [];
        foreach ($this->fields->fieldsFor($board) as $field) {
            if ($field->getBoardId() === $board->getId()) {
                $own[$field->getName()] = $field;
            }
        }
        $kept = [];
        foreach ($specs as $position => $spec) {
            $current = $own[$spec->name] ?? null;
            if ($current instanceof TaskCustomField && $current->getType() !== $spec->type) {
                $this->drop($actor, $current, $dropFieldValues);
                $current = null;
            }
            try {
                if ($current instanceof TaskCustomField) {
                    $options = array_map(static fn (array $option): array => ['id' => $current->optionNamed($option['name'])['id'] ?? null, 'name' => $option['name'], 'color' => $option['color']], $spec->options);
                    $this->fields->update($actor, $current, $spec->name, $options, $position);
                } else {
                    $this->fields->define($actor, $board, null, $spec->name, $spec->type, $spec->options, $position);
                }
            } catch (TaskCustomFieldRefusedException $refused) {
                throw $this->fieldRefusal($refused);
            }
            $kept[$spec->name] = true;
        }
        foreach ($own as $name => $field) {
            if (!isset($kept[$name])) {
                $this->drop($actor, $field, $dropFieldValues);
            }
        }
    }

    /**
     * @throws TaskBoardRestructureRefusedException
     */
    private function drop(object $actor, TaskCustomField $field, bool $dropFieldValues): void
    {
        if (!$dropFieldValues && $this->fields->hasValues($field)) {
            throw new TaskBoardRestructureRefusedException(TaskBoardRestructureRefusedException::FIELDS_IN_USE, ['fields' => $field->getName()]);
        }
        try {
            $this->fields->delete($actor, $field);
        } catch (TaskCustomFieldRefusedException $refused) {
            throw $this->fieldRefusal($refused);
        }
    }

    private function fieldRefusal(TaskCustomFieldRefusedException $refused): TaskBoardRestructureRefusedException
    {
        return new TaskBoardRestructureRefusedException(
            $refused->reason === TaskCustomFieldRefusedException::NOT_ALLOWED ? TaskBoardRestructureRefusedException::NOT_ALLOWED : TaskBoardRestructureRefusedException::INVALID,
            ['field' => 'fields'],
        );
    }

    /**
     * @param list<BoardColumnSpec> $columns
     */
    private function columnKeys(array $columns): string
    {
        return implode("\n", array_map(static fn (BoardColumnSpec $column): string => $column->name . '|' . $column->type->value, $columns));
    }
}
