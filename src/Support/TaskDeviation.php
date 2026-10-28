<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Support;

use Nowo\TaskBoardBundle\Entity\Task;

use function abs;
use function intdiv;
use function round;
use function sprintf;

/**
 * Since 1.15: the deviation of a task (or of a group of tasks) between its estimate and the time spent on it.
 *
 * - `difference` = spent − estimate (minutes, positive = over), `percent` = difference / estimate × 100;
 * - `status`: `none` without an estimate, `over` when more was spent than estimated, `under` when a completed task
 *   took less than {@see self::UNDER_THRESHOLD_PERCENT} % of its estimate, otherwise `on_track` (an open task below its
 *   estimate is still on track).
 */
final readonly class TaskDeviation
{
    public const STATUS_NONE     = 'none';
    public const STATUS_ON_TRACK = 'on_track';
    public const STATUS_OVER     = 'over';
    public const STATUS_UNDER    = 'under';

    /** A completed task that used less than this share of its estimate reads as "under". */
    public const UNDER_THRESHOLD_PERCENT = 90;

    public function __construct(
        public ?int $estimatedMinutes,
        public int $spentMinutes,
        public bool $completed = false,
    ) {
    }

    public static function of(Task $task): self
    {
        return new self($task->getEstimatedMinutes(), $task->getLoggedMinutes(), $task->isCompleted());
    }

    /**
     * The tasks together: the sum of their estimates (null when none has one), of their spent time, completed when
     * every task is.
     *
     * @param iterable<Task> $tasks
     */
    public static function sum(iterable $tasks): self
    {
        $estimate  = null;
        $spent     = 0;
        $completed = true;
        $any       = false;
        foreach ($tasks as $task) {
            $any = true;
            if ($task->getEstimatedMinutes() !== null) {
                $estimate = ($estimate ?? 0) + $task->getEstimatedMinutes();
            }

            $spent += $task->getLoggedMinutes();
            $completed = $completed && $task->isCompleted();
        }

        return new self($estimate, $spent, $any && $completed);
    }

    public function hasEstimate(): bool
    {
        return $this->estimatedMinutes !== null && $this->estimatedMinutes > 0;
    }

    /** Spent − estimate in minutes (positive: over the estimate); null without an estimate. */
    public function difference(): ?int
    {
        return $this->hasEstimate() ? $this->spentMinutes - (int) $this->estimatedMinutes : null;
    }

    /** The difference as a whole percentage of the estimate; null without an estimate. */
    public function percent(): ?int
    {
        $difference = $this->difference();

        return $difference === null ? null : (int) round($difference * 100 / (int) $this->estimatedMinutes);
    }

    /** Spent as a whole percentage of the estimate (the Gantt bar fill); null without an estimate. */
    public function spentPercent(): ?int
    {
        return $this->hasEstimate() ? (int) round($this->spentMinutes * 100 / (int) $this->estimatedMinutes) : null;
    }

    public function isOver(): bool
    {
        return $this->hasEstimate() && $this->spentMinutes > (int) $this->estimatedMinutes;
    }

    public function status(): string
    {
        if (!$this->hasEstimate()) {
            return self::STATUS_NONE;
        }

        if ($this->isOver()) {
            return self::STATUS_OVER;
        }

        if ($this->completed && (int) $this->spentPercent() < self::UNDER_THRESHOLD_PERCENT) {
            return self::STATUS_UNDER;
        }

        return self::STATUS_ON_TRACK;
    }

    /**
     * Minutes as "45 min", "2 h", "1 h 30 min"; `$signed` adds "+" / "−" (the difference); null gives "—".
     */
    public static function formatMinutes(?int $minutes, bool $signed = false): string
    {
        if ($minutes === null) {
            return '—';
        }

        $sign  = $signed && $minutes > 0 ? '+' : ($minutes < 0 ? '−' : '');
        $total = abs($minutes);
        $hours = intdiv($total, 60);
        $rest  = $total % 60;

        $text = match (true) {
            $hours === 0 => sprintf('%d min', $rest),
            $rest === 0  => sprintf('%d h', $hours),
            default      => sprintf('%d h %d min', $hours, $rest),
        };

        return $sign . $text;
    }
}
