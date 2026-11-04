<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/** Since 1.23: files of a run. */
final readonly class FileCounts
{
    public function __construct(
        public int $copied = 0,
        public int $reused = 0,
        public int $refused = 0,
        public int $bytes = 0,
    ) {
    }

    /**
     * @return array{copied: int, reused: int, refused: int, bytes: int}
     */
    public function toArray(): array
    {
        return ['copied' => $this->copied, 'reused' => $this->reused, 'refused' => $this->refused, 'bytes' => $this->bytes];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(SyncValues::int($data['copied'] ?? 0), SyncValues::int($data['reused'] ?? 0), SyncValues::int($data['refused'] ?? 0), SyncValues::int($data['bytes'] ?? 0));
    }
}
