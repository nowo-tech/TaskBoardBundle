<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Form payload for creating or editing a board column.
 */
final class BoardColumnFormData
{
    /**
     * @param string|null $icon Icon name such as `tabler:bug` (since 1.11)
     */
    public function __construct(
        public string $name = '',
        public ?string $color = null,
        public ?string $icon = null,
        // Since 2.16.0 (spec 234): only people may move tasks into the column (see BoardColumn::isHumansOnly()).
        public bool $humansOnly = false,
    ) {
    }
}
