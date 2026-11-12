<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use InvalidArgumentException;

use function array_keys;
use function array_map;
use function get_debug_type;
use function sprintf;
use function strval;

/**
 * Since 2.14.0: the annotated days of one person (or of the viewer's calendar) in a range, at most one per day. For a day
 * given twice, a non-working annotation wins over a working one; otherwise the first one stays.
 */
final readonly class CalendarDayAnnotations
{
    /** @var array<string, CalendarDayAnnotation> by date */
    private array $days;

    /**
     * @param iterable<CalendarDayAnnotation> $annotations
     */
    public function __construct(iterable $annotations = [])
    {
        $days = [];
        foreach ($annotations as $annotation) {
            // @phpstan-ignore instanceof.alwaysTrue (a host's provider is plain PHP: refuse anything else loudly)
            if (!$annotation instanceof CalendarDayAnnotation) {
                throw new InvalidArgumentException(sprintf('Calendar day annotations hold %s, %s given.', CalendarDayAnnotation::class, get_debug_type($annotation)));
            }
            $known = $days[$annotation->date] ?? null;
            if ($known === null || (!$known->nonWorking && $annotation->nonWorking)) {
                $days[$annotation->date] = $annotation;
            }
        }
        $this->days = $days;
    }

    public static function none(): self
    {
        return new self();
    }

    public function for(string $date): ?CalendarDayAnnotation
    {
        return $this->days[$date] ?? null;
    }

    public function isNonWorking(string $date): bool
    {
        return ($this->days[$date] ?? null)?->nonWorking === true;
    }

    public function isEmpty(): bool
    {
        return $this->days === [];
    }

    /**
     * @return list<string>
     */
    public function dates(): array
    {
        return array_map(strval(...), array_keys($this->days));
    }
}
