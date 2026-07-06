<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Form payload for creating a board column.
 */
final class BoardColumnFormData
{
    public function __construct(
        public string $name = '',
        public ?string $color = null,
    ) {
    }
}
