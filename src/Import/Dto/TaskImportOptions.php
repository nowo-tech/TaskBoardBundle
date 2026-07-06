<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Dto;

/**
 * Import behaviour flags shared by all importers.
 */
final readonly class TaskImportOptions
{
    public function __construct(
        public bool $createMissingColumns = true,
        public bool $skipExisting = true,
    ) {
    }
}
