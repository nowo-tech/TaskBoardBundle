<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

/**
 * Since 1.23: why remote tasks were skipped. `locallyDeleted` is shown as "skipped (deleted here)".
 */
final readonly class SkipCounts
{
    public function __construct(
        public int $readOnly = 0,
        public int $elsewhere = 0,
        public int $locallyDeleted = 0,
        public int $locallyArchived = 0,
        public int $repeated = 0,
    ) {
    }

    public function total(): int
    {
        return $this->readOnly + $this->elsewhere + $this->locallyDeleted + $this->locallyArchived + $this->repeated;
    }

    /**
     * @return array{readOnly: int, elsewhere: int, locallyDeleted: int, locallyArchived: int, repeated: int}
     */
    public function toArray(): array
    {
        return ['readOnly' => $this->readOnly, 'elsewhere' => $this->elsewhere, 'locallyDeleted' => $this->locallyDeleted, 'locallyArchived' => $this->locallyArchived, 'repeated' => $this->repeated];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            readOnly: SyncValues::int($data['readOnly'] ?? 0),
            elsewhere: SyncValues::int($data['elsewhere'] ?? 0),
            locallyDeleted: SyncValues::int($data['locallyDeleted'] ?? 0),
            locallyArchived: SyncValues::int($data['locallyArchived'] ?? 0),
            repeated: SyncValues::int($data['repeated'] ?? 0),
        );
    }
}
