<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use InvalidArgumentException;

use function is_array;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * Since 2.24.0: one event of a {@see CalendarEventSourceInterface}, as the source describes it. The bundle prints the title as
 * given (escaped) and nothing else, so return only what this viewer may read.
 *
 * Times are timezone-safe: `start` and `end` are instants in any timezone; the calendar shows them in its own
 * (`calendar.events.timezone`). A timed event ends at `end` (excluded; `end` equal to `start` is a point in time). An
 * all-day event (`allDay: true`) is the days from the date of `start` to the date of `end`, both included, read in the
 * timezone of each value (never converted), so `2030-03-04` stays that day for every viewer.
 *
 * - `id`: the source's id of the event, 1 to 128 printable characters; unique within the source.
 * - `url`: where the title links to: `https://`, `http://` or a path of this site (`/...`, not `//...`); null: no link.
 * - `color`: `#rgb` or `#rrggbb`, the accent of the event; null: the category's or the default one.
 * - `category`: a CSS-friendly token (`nowo-task-cal__event--{category}`), 1 to 32 of `a-z`, `0-9`, `_`, `-`.
 * - `readOnly`: true (the default) keeps the event in place; false lets the viewer move it when the source implements
 *   {@see CalendarEventReschedulerInterface}.
 * - `version`: an opaque concurrency token sent back with a reschedule (optimistic concurrency); null: none.
 * - `tenantKey`: the tenant of the event. It must be the viewer's (`CalendarViewer::$tenantKey`, null without tenancy),
 *   byte for byte, or the bundle drops the event.
 */
final readonly class CalendarEvent
{
    public const MAX_ID_LENGTH      = 128;
    public const MAX_TITLE_LENGTH   = 200;
    public const MAX_URL_LENGTH     = 2048;
    public const MAX_VERSION_LENGTH = 128;

    public string $title;

    public function __construct(
        public string $id,
        string $title,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public bool $allDay = false,
        public ?string $url = null,
        public ?string $color = null,
        public ?string $category = null,
        public bool $readOnly = true,
        public ?string $version = null,
        public ?string $tenantKey = null,
    ) {
        if (!self::isPrintable($id, self::MAX_ID_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A calendar event needs an id of 1 to %d printable characters.', self::MAX_ID_LENGTH));
        }
        $title = trim($title);
        if (!self::isPrintable($title, self::MAX_TITLE_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A calendar event needs a title of 1 to %d printable characters.', self::MAX_TITLE_LENGTH));
        }
        $this->title = $title;
        if ($allDay ? $end->format('Y-m-d') < $start->format('Y-m-d') : $end < $start) {
            throw new InvalidArgumentException('A calendar event ends on or after its start.');
        }
        if ($url !== null && !$this->isSafeUrl($url)) {
            throw new InvalidArgumentException('A calendar event URL is an http(s) URL or a local path.');
        }
        if ($color !== null && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/D', $color) !== 1) {
            throw new InvalidArgumentException('A calendar event color is #rgb or #rrggbb.');
        }
        if ($category !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $category) !== 1) {
            throw new InvalidArgumentException('A calendar event category is 1 to 32 of a-z, 0-9, "_" and "-".');
        }
        if ($version !== null && !self::isPrintable($version, self::MAX_VERSION_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A calendar event version is 1 to %d printable characters.', self::MAX_VERSION_LENGTH));
        }
    }

    /** The first day of an all-day event (`Y-m-d`, in the timezone of `start`). */
    public function firstDay(): string
    {
        return $this->start->format('Y-m-d');
    }

    /** The last day of an all-day event (`Y-m-d`, in the timezone of `end`). */
    public function lastDay(): string
    {
        return $this->end->format('Y-m-d');
    }

    /**
     * Since 2.24.0 (audit UW-TB-6): 1 to `$max` characters of valid UTF-8 with no control character (C0 and C1, `\p{Cc}`),
     * no format character (`\p{Cf}`: bidirectional marks, overrides and isolates, the Arabic letter mark U+061C, zero-width
     * characters, the soft hyphen U+00AD, tag characters...), no line or paragraph separator (U+2028, U+2029) and no Hangul
     * filler (U+115F, U+1160, U+3164, U+FFA0; since 2.25.0, audit UW2-2). Used for ids, titles and versions.
     */
    public static function isPrintable(string $value, int $max): bool
    {
        return $value !== '' && mb_strlen($value) <= $max && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{115F}\x{1160}\x{3164}\x{FFA0}]/u', $value) === 0;
    }

    private function isSafeUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['scheme'], $parts['host']) && preg_match('/^https?$/iD', $parts['scheme']) === 1;
    }
}
