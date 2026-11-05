<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use function is_string;
use function str_starts_with;
use function substr;

/**
 * Since 1.25: the time phase of a run ({@see SyncReport::$time}): the mode (`admin` / `member` / `fallback`, null = no
 * time phase), the windows read (and halved), the entries delivered to the host sink, the running timers and task-less
 * entries left out, and the tasks the explicit per-task fallback read.
 */
final readonly class TimeCounts
{
    /** Prefix of these counters while the run collects them. */
    public const PREFIX = 'time.';

    public function __construct(
        public ?string $mode = null,
        public int $windows = 0,
        public int $windowsSplit = 0,
        public int $entries = 0,
        public int $skippedRunning = 0,
        public int $skippedTaskless = 0,
        public int $fallbackTasks = 0,
        /** Since 1.25 (audit FP-I2): entries of people not mapped to a user, never handed to the sink. */
        public int $skippedUnmapped = 0,
    ) {
    }

    /**
     * The counters of the run's `time.<name>` keys (other keys are ignored).
     *
     * @param array<string, int> $counters
     */
    public static function fromCounters(?string $mode, array $counters): self
    {
        $values = ['mode' => $mode];
        foreach ($counters as $name => $value) {
            if (str_starts_with($name, self::PREFIX)) {
                $values[substr($name, 5)] = $value;
            }
        }

        return self::fromArray($values);
    }

    /**
     * The `time.<name>` keys of the non-zero counters (the mode is not a counter).
     *
     * @return array<string, int>
     */
    public function toCounters(): array
    {
        $counters = [];
        foreach ($this->toArray() as $name => $value) {
            if ($name !== 'mode' && $value !== 0) {
                $counters[self::PREFIX . $name] = (int) $value;
            }
        }

        return $counters;
    }

    /**
     * @return array{mode: string|null, windows: int, windowsSplit: int, entries: int, skippedRunning: int, skippedTaskless: int, fallbackTasks: int, skippedUnmapped: int}
     */
    public function toArray(): array
    {
        return [
            'mode'            => $this->mode,
            'windows'         => $this->windows,
            'windowsSplit'    => $this->windowsSplit,
            'entries'         => $this->entries,
            'skippedRunning'  => $this->skippedRunning,
            'skippedTaskless' => $this->skippedTaskless,
            'fallbackTasks'   => $this->fallbackTasks,
            'skippedUnmapped' => $this->skippedUnmapped,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['mode'] ?? null) ? $data['mode'] : null,
            SyncValues::int($data['windows'] ?? 0),
            SyncValues::int($data['windowsSplit'] ?? 0),
            SyncValues::int($data['entries'] ?? 0),
            SyncValues::int($data['skippedRunning'] ?? 0),
            SyncValues::int($data['skippedTaskless'] ?? 0),
            SyncValues::int($data['fallbackTasks'] ?? 0),
            SyncValues::int($data['skippedUnmapped'] ?? 0),
        );
    }
}
