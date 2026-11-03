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
        /**
         * @deprecated since 1.22, ignored: every import is an idempotent upsert — a row already imported (same source
         *             and id, or same fingerprint) updates its task instead of being skipped or duplicated.
         */
        public bool $skipExisting = true,
    ) {
    }
}
