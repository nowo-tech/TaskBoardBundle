<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

use InvalidArgumentException;

use function preg_match;

/**
 * Since 2.24.0: what a {@see CalendarEventReschedulerInterface} answers. Build it with the named constructors.
 *
 * - `ok()`: moved; `version` is the event's new concurrency token (null: none).
 * - `conflict()`: the event no longer starts at the expected time or has another version; nothing was written. The calendar
 *   answers 409 with the domain code in `X-Error-Code` and in the body (`CALENDAR_EVENT_CHANGED` by default), and no
 *   `X-Conflict-Code`: the viewer reloads, a blind retry would fail again.
 * - `lockConflict()`: a database lock conflict (deadlock, lock wait timeout, serialization failure). `committed: false` (nothing
 *   written, retry is safe) answers `CONFLICT_001` with `Retry-After`; `committed: true` (part of the work was saved)
 *   `CONFLICT_002` without it (REQ-RUNTIME-003 rule 6).
 * - `forbidden()`: 403 with the code (`CALENDAR_EVENT_FORBIDDEN` by default) in `X-Error-Code`.
 * - `notFound()`: 404, the same answer as an unknown event: never tell an event of someone else exists.
 *
 * A code is 1 to 64 of `A-Z`, `0-9` and `_`, starting with a letter; `CONFLICT` and every `CONFLICT_…` code are reserved for lock conflicts (since 2.25.0, audit UW2-3).
 */
final readonly class CalendarEventRescheduleResult
{
    public const CODE_CHANGED   = 'CALENDAR_EVENT_CHANGED';
    public const CODE_FORBIDDEN = 'CALENDAR_EVENT_FORBIDDEN';
    public const CODE_NOT_FOUND = 'CALENDAR_EVENT_NOT_FOUND';

    private function __construct(
        public CalendarEventRescheduleStatus $status,
        public ?string $code = null,
        public ?string $version = null,
        public bool $committed = false,
        public ?int $retryAfterSeconds = null,
    ) {
        // Audit UW-TB-5 / UW2-3: CONFLICT and CONFLICT_* belong to the platform's lock-conflict codes (X-Conflict-Code), never a domain code.
        if ($code !== null && (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code) !== 1 || preg_match('/^CONFLICT(_|$)/D', $code) === 1)) {
            throw new InvalidArgumentException('A calendar event error code is 1 to 64 of A-Z, 0-9 and "_", starting with a letter.');
        }
        if ($retryAfterSeconds !== null && $retryAfterSeconds < 0) {
            throw new InvalidArgumentException('Retry-After is zero or more seconds.');
        }
    }

    public static function ok(?string $version = null): self
    {
        if ($version !== null && !CalendarEvent::isPrintable($version, CalendarEvent::MAX_VERSION_LENGTH)) {
            throw new InvalidArgumentException('A calendar event version is 1 to 128 printable characters.');
        }

        return new self(CalendarEventRescheduleStatus::Ok, version: $version);
    }

    public static function conflict(string $code = self::CODE_CHANGED): self
    {
        return new self(CalendarEventRescheduleStatus::Conflict, $code);
    }

    public static function lockConflict(bool $committed = false, ?int $retryAfterSeconds = null): self
    {
        return new self(CalendarEventRescheduleStatus::LockConflict, committed: $committed, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function forbidden(string $code = self::CODE_FORBIDDEN): self
    {
        return new self(CalendarEventRescheduleStatus::Forbidden, $code);
    }

    public static function notFound(): self
    {
        return new self(CalendarEventRescheduleStatus::NotFound, self::CODE_NOT_FOUND);
    }
}
