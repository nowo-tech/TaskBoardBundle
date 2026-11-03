<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Dto;

/**
 * Outcome summary for a board import run.
 *
 * Since 1.22 every row ends in exactly one of `created`, `updated`, `unchanged` or `skipped` (a row of another board,
 * or a repeated row of the file).
 */
final readonly class TaskImportResult
{
    public function __construct(
        public int $created = 0,
        public int $skipped = 0,
        public int $columnsCreated = 0,
        /** @var list<string> */
        public array $errors = [],
        /** @var list<string> */
        public array $warnings = [],
        /** Since 1.18: Gantt dependencies created between the imported tasks. */
        public int $dependenciesCreated = 0,
        /** Since 1.22: rows that matched a task of the board and changed it. */
        public int $updated = 0,
        /** Since 1.22: rows that matched a task of the board that already had their values. */
        public int $unchanged = 0,
    ) {
    }

    public function withCreated(int $created): self
    {
        return $this->with(created: $created);
    }

    public function withSkipped(int $skipped): self
    {
        return $this->with(skipped: $skipped);
    }

    public function withColumnsCreated(int $columnsCreated): self
    {
        return $this->with(columnsCreated: $columnsCreated);
    }

    /**
     * @param list<string> $errors
     */
    public function withErrors(array $errors): self
    {
        return $this->with(errors: $errors);
    }

    /**
     * @param list<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return $this->with(warnings: $warnings);
    }

    public function withUpdated(int $updated): self
    {
        return $this->with(updated: $updated);
    }

    public function withUnchanged(int $unchanged): self
    {
        return $this->with(unchanged: $unchanged);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @param list<string>|null $errors
     * @param list<string>|null $warnings
     */
    private function with(
        ?int $created = null,
        ?int $skipped = null,
        ?int $columnsCreated = null,
        ?array $errors = null,
        ?array $warnings = null,
        ?int $updated = null,
        ?int $unchanged = null,
    ): self {
        return new self(
            created: $created ?? $this->created,
            skipped: $skipped ?? $this->skipped,
            columnsCreated: $columnsCreated ?? $this->columnsCreated,
            errors: $errors ?? $this->errors,
            warnings: $warnings ?? $this->warnings,
            dependenciesCreated: $this->dependenciesCreated,
            updated: $updated ?? $this->updated,
            unchanged: $unchanged ?? $this->unchanged,
        );
    }
}
