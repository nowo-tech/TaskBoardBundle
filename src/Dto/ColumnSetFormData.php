<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

/**
 * Form payload for saving a board's columns as a column set (since 1.11).
 */
final class ColumnSetFormData
{
    public function __construct(
        public string $name = '',
    ) {
    }
}
