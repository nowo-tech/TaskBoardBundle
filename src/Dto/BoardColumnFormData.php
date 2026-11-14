<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\ColumnType;

/**
 * Form payload for creating or editing a board column.
 */
final class BoardColumnFormData
{
    /**
     * @param string|null $icon Icon name such as `tabler:bug` (since 1.11)
     * @param ColumnType|null $type since 2.18.0 (FR-033): the column's type; null keeps it (a new column: by position)
     */
    public function __construct(
        public string $name = '',
        public ?string $color = null,
        public ?string $icon = null,
        public ?ColumnType $type = null,
        // Since 2.16.0 (spec 234): only people may move tasks into the column (see BoardColumn::isHumansOnly()).
        public bool $humansOnly = false,
    ) {
    }
}
