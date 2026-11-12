<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

use function mb_strlen;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Since 2.14.0 (Beacon spec 204): one day as the host describes it: whether it is a non-working day, the label to show and its
 * kind. The bundle prints the label as given (escaped) and nothing else, so a host never passes a medical or other special
 * category reason here: "Absent" is enough (GDPR art. 9).
 */
final readonly class CalendarDayAnnotation
{
    /** Longest label: a badge, not a description. */
    public const MAX_LABEL_LENGTH = 80;

    public string $label;

    public function __construct(
        public string $date,
        public bool $nonWorking,
        string $label,
        public CalendarDayKind $kind = CalendarDayKind::Other,
    ) {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('A calendar day annotation needs a Y-m-d date, "%s" given.', $date));
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $label) !== 0) {
            throw new InvalidArgumentException(sprintf('A calendar day annotation needs a label of 1 to %d printable characters.', self::MAX_LABEL_LENGTH));
        }
        $this->label = $label;
    }
}
