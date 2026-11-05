<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use function str_starts_with;
use function substr;

/**
 * Since 1.25: the people directory counters of a run ({@see SyncReport::$people}). `seen` counts the person rows the
 * run created or refreshed (once per page); `autoUnmatched` the automatic mappings dropped because the e-mail changed
 * or the user is gone.
 */
final readonly class PeopleCounts
{
    /** Prefix of these counters while the run collects them. */
    public const PREFIX = 'people.';

    public function __construct(
        public int $seen = 0,
        public int $created = 0,
        public int $autoMatched = 0,
        public int $suggested = 0,
        public int $unmatchedNew = 0,
        public int $changedEmail = 0,
        public int $autoUnmatched = 0,
    ) {
    }

    /**
     * The counters of the run's `people.<name>` keys (other keys are ignored).
     *
     * @param array<string, int> $counters
     */
    public static function fromCounters(array $counters): self
    {
        $values = [];
        foreach ($counters as $name => $value) {
            if (str_starts_with($name, self::PREFIX)) {
                $values[substr($name, 7)] = $value;
            }
        }

        return self::fromArray($values);
    }

    /**
     * The `people.<name>` keys of the non-zero counters (the inverse of {@see self::fromCounters()}).
     *
     * @return array<string, int>
     */
    public function toCounters(): array
    {
        $counters = [];
        foreach ($this->toArray() as $name => $value) {
            if ($value !== 0) {
                $counters[self::PREFIX . $name] = $value;
            }
        }

        return $counters;
    }

    /**
     * @return array{seen: int, created: int, autoMatched: int, suggested: int, unmatchedNew: int, changedEmail: int, autoUnmatched: int}
     */
    public function toArray(): array
    {
        return [
            'seen'          => $this->seen,
            'created'       => $this->created,
            'autoMatched'   => $this->autoMatched,
            'suggested'     => $this->suggested,
            'unmatchedNew'  => $this->unmatchedNew,
            'changedEmail'  => $this->changedEmail,
            'autoUnmatched' => $this->autoUnmatched,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            SyncValues::int($data['seen'] ?? 0),
            SyncValues::int($data['created'] ?? 0),
            SyncValues::int($data['autoMatched'] ?? 0),
            SyncValues::int($data['suggested'] ?? 0),
            SyncValues::int($data['unmatchedNew'] ?? 0),
            SyncValues::int($data['changedEmail'] ?? 0),
            SyncValues::int($data['autoUnmatched'] ?? 0),
        );
    }
}
