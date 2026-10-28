<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Form payload for adding a column set's columns to a board (since 1.11).
 */
final class ColumnSetApplyFormData
{
    public function __construct(
        public ?string $columnSetId = null,
    ) {
    }
}
