<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import\Dto;

/**
 * Outcome summary for a board import run.
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
    ) {
    }

    public function withCreated(int $created): self
    {
        return new self($created, $this->skipped, $this->columnsCreated, $this->errors, $this->warnings);
    }

    public function withSkipped(int $skipped): self
    {
        return new self($this->created, $skipped, $this->columnsCreated, $this->errors, $this->warnings);
    }

    public function withColumnsCreated(int $columnsCreated): self
    {
        return new self($this->created, $this->skipped, $columnsCreated, $this->errors, $this->warnings);
    }

    /**
     * @param list<string> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($this->created, $this->skipped, $this->columnsCreated, $errors, $this->warnings);
    }

    /**
     * @param list<string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self($this->created, $this->skipped, $this->columnsCreated, $this->errors, $warnings);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
