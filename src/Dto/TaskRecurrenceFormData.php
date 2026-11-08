<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;

/**
 * Since 2.3.0 (spec 215): the Repeat form.
 */
final class TaskRecurrenceFormData
{
    public const END_NEVER = 'never';

    public const END_DATE = 'date';

    public const END_COUNT = 'count';

    /**
     * @param list<int> $weekdays
     */
    public function __construct(
        public string $mode = TaskRecurrenceRule::MODE_FIXED,
        public bool $nextOnCompletion = false,
        public int $interval = 1,
        public string $unit = 'week',
        public array $weekdays = [],
        public ?int $monthDay = null,
        public string $end = self::END_NEVER,
        public ?DateTimeImmutable $endDate = null,
        public ?int $endCount = null,
        public int $leadDays = 1,
        public bool $copySubtasks = true,
        public bool $copyMembers = true,
    ) {
    }

    public static function fromSeries(TaskRecurrence $series): self
    {
        $rule = $series->rule();

        return new self(
            mode: $rule->mode,
            nextOnCompletion: $series->isNextOnCompletion(),
            interval: $rule->interval,
            unit: $rule->unit,
            weekdays: $rule->weekdays,
            monthDay: $rule->monthDay,
            end: $rule->endDate instanceof DateTimeImmutable ? self::END_DATE : ($rule->endCount !== null ? self::END_COUNT : self::END_NEVER),
            endDate: $rule->endDate,
            endCount: $rule->endCount,
            leadDays: $series->getLeadDays(),
            copySubtasks: $series->copiesSubtasks(),
            copyMembers: $series->copiesMembers(),
        );
    }

    /** The rule; its anchor is set by the manager from the task's date. */
    public function rule(DateTimeImmutable $anchor): TaskRecurrenceRule
    {
        return new TaskRecurrenceRule(
            $this->mode,
            $this->unit,
            $this->interval,
            $anchor,
            $this->unit === 'week' ? $this->weekdays : [],
            $this->unit === 'month' ? $this->monthDay : null,
            $this->end === self::END_DATE ? $this->endDate : null,
            $this->end === self::END_COUNT ? $this->endCount : null,
        );
    }
}
