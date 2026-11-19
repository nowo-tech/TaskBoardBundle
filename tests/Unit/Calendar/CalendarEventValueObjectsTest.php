<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEvent;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEventReschedule;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRescheduleResult;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRescheduleStatus;
use Nowo\TaskBoardBundle\Calendar\CalendarEventShown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const DATE_ATOM;

/**
 * Since 2.24.0: the values of the calendar event sources validate what a source hands over, compare days and instants in the
 * right timezone, and move an event by whole days keeping its time of day and duration, across a daylight-saving change too.
 */
#[CoversClass(CalendarEvent::class)]
#[CoversClass(CalendarEventRange::class)]
#[CoversClass(CalendarEventReschedule::class)]
#[CoversClass(CalendarEventRescheduleResult::class)]
#[CoversClass(CalendarEventShown::class)]
final class CalendarEventValueObjectsTest extends TestCase
{
    public function testAnEventKeepsWhatTheSourceGave(): void
    {
        $event = new CalendarEvent('m-1', '  Call  ', new DateTimeImmutable('2030-03-04 09:30'), new DateTimeImmutable('2030-03-04 09:30'), url: 'https://example.com/m/1', color: '#abc', category: 'call_1', readOnly: false, version: 'v1', tenantKey: 'acme');

        self::assertSame(['m-1', 'Call', false, 'https://example.com/m/1', '#abc', 'call_1', false, 'v1', 'acme'], [$event->id, $event->title, $event->allDay, $event->url, $event->color, $event->category, $event->readOnly, $event->version, $event->tenantKey]);
        self::assertSame(['2030-03-04', '2030-03-04'], [$event->firstDay(), $event->lastDay()]);
        self::assertSame('/m/1', (new CalendarEvent('m', 'T', new DateTimeImmutable(), new DateTimeImmutable(), url: '/m/1'))->url);
        self::assertTrue((new CalendarEvent('m', 'T', new DateTimeImmutable('2030-03-04 23:00'), new DateTimeImmutable('2030-03-04 01:00'), allDay: true))->allDay, 'an all-day event compares days');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'empty id' => [['id' => '']];
        yield 'long id' => [['id' => str_repeat('i', 129)]];
        yield 'control character in id' => [['id' => "m\n1"]];
        yield 'blank title' => [['title' => '   ']];
        yield 'C1 control in title' => [['title' => "Call\u{0085}"]];
        yield 'bidi override in title' => [['title' => "Call \u{202E}gnp.exe"]];
        yield 'bidi isolate in id' => [['id' => "m\u{2066}1"]];
        yield 'zero-width space in title' => [['title' => "Ca\u{200B}ll"]];
        yield 'zero-width no-break space in version' => [['version' => "\u{FEFF}v1"]];
        yield 'word joiner in id' => [['id' => "m\u{2060}1"]];
        // Since 2.25.0 (audit UW2-2): every format character, line and paragraph separators and the Hangul fillers.
        yield 'Arabic letter mark in title' => [['title' => "Call\u{061C}"]];
        yield 'line separator in title' => [['title' => "Call\u{2028}now"]];
        yield 'paragraph separator in id' => [['id' => "m\u{2029}1"]];
        yield 'soft hyphen in id' => [['id' => "m\u{00AD}1"]];
        yield 'tag character in title' => [['title' => "Call\u{E0041}"]];
        yield 'Hangul choseong filler in title' => [['title' => "\u{115F}Call"]];
        yield 'Hangul jungseong filler in id' => [['id' => "m\u{1160}1"]];
        yield 'Hangul filler in title' => [['title' => "Call\u{3164}"]];
        yield 'halfwidth Hangul filler in version' => [['version' => "v\u{FFA0}"]];
        yield 'invalid UTF-8 title' => [['title' => "Call \xC3\x28"]];
        yield 'long title' => [['title' => str_repeat('t', 201)]];
        yield 'end before start' => [['end' => new DateTimeImmutable('2030-03-04 09:00')]];
        yield 'all-day end before start' => [['allDay' => true, 'start' => new DateTimeImmutable('2030-03-05'), 'end' => new DateTimeImmutable('2030-03-04')]];
        yield 'javascript url' => [['url' => 'javascript:alert(1)']];
        yield 'protocol-relative url' => [['url' => '//evil.example/x']];
        yield 'url with a space' => [['url' => '/a b']];
        yield 'url with a backslash' => [['url' => '/\\evil']];
        yield 'url without host' => [['url' => 'https:///x']];
        yield 'ftp url' => [['url' => 'ftp://example.com/x']];
        yield 'long url' => [['url' => '/' . str_repeat('a', 2048)]];
        yield 'not a url' => [['url' => 'http://:80']];
        yield 'named color' => [['color' => 'red']];
        yield 'css injection color' => [['color' => '#fff;background:url(x)']];
        yield 'uppercase category' => [['category' => 'Call']];
        yield 'long category' => [['category' => str_repeat('c', 33)]];
        yield 'empty version' => [['version' => '']];
        yield 'long version' => [['version' => str_repeat('v', 129)]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('invalid')]
    public function testAnInvalidEventIsRefused(array $values): void
    {
        $values += ['id' => 'm-1', 'title' => 'Call', 'start' => new DateTimeImmutable('2030-03-04 09:30'), 'end' => new DateTimeImmutable('2030-03-04 10:00'), 'allDay' => false, 'url' => null, 'color' => null, 'category' => null, 'version' => null];

        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore argument.type
        new CalendarEvent($values['id'], $values['title'], $values['start'], $values['end'], $values['allDay'], $values['url'], $values['color'], $values['category'], true, $values['version']);
    }

    public function testTheRangeIsTheDaysAsInstantsOfTheTimezone(): void
    {
        $range = new CalendarEventRange(new CalendarDateRange('2030-03-04', '2030-03-10'), 'Europe/Madrid');
        $utc   = new DateTimeZone('UTC');

        self::assertSame('2030-03-04T00:00:00+01:00', $range->from->format(DATE_ATOM));
        self::assertSame('2030-03-11T00:00:00+01:00', $range->to->format(DATE_ATOM));
        $timed = static fn (string $start, string $end): CalendarEvent => new CalendarEvent('e', 'T', new DateTimeImmutable($start, $utc), new DateTimeImmutable($end, $utc));
        // 23:30 UTC on the 3rd is 00:30 on the 4th in Madrid.
        self::assertTrue($range->overlaps($timed('2030-03-03 23:30', '2030-03-03 23:45')));
        self::assertFalse($range->overlaps($timed('2030-03-03 22:00', '2030-03-03 23:00')), 'ends at midnight Madrid: the day before');
        self::assertTrue($range->overlaps($timed('2030-03-03 20:00', '2030-03-04 01:00')), 'from before into the range');
        self::assertFalse($range->overlaps($timed('2030-03-10 23:00', '2030-03-10 23:30')), 'midnight after the last day');
        self::assertTrue($range->overlaps($timed('2030-03-03 23:00', '2030-03-03 23:00')), 'a point in time at the first instant');
        self::assertFalse($range->overlaps($timed('2030-03-03 22:59', '2030-03-03 22:59')));
        $allDay = static fn (string $first, string $last): CalendarEvent => new CalendarEvent('e', 'T', new DateTimeImmutable($first), new DateTimeImmutable($last), allDay: true);
        self::assertTrue($range->overlaps($allDay('2030-03-01', '2030-03-04')));
        self::assertTrue($range->overlaps($allDay('2030-03-10', '2030-03-12')));
        self::assertFalse($range->overlaps($allDay('2030-03-01', '2030-03-03')));
        self::assertFalse($range->overlaps($allDay('2030-03-11', '2030-03-12')));
    }

    public function testATimedEventMovesByDaysKeepingItsLocalTimeAndDurationAcrossDaylightSaving(): void
    {
        $madrid = new DateTimeZone('Europe/Madrid');
        // Friday 27 March 2026 09:30 Madrid (UTC+1) to Monday 30 March, after the change to UTC+2.
        $start  = new DateTimeImmutable('2026-03-27T08:30:00+00:00');
        $end    = new DateTimeImmutable('2026-03-27T09:45:00+00:00');
        $change = CalendarEventReschedule::toDay('m-1', $start, $end, 'v1', false, '2026-03-30', $madrid);

        self::assertSame('2026-03-30T09:30:00+02:00', $change->start->format(DATE_ATOM));
        self::assertSame('2026-03-30T10:45:00+02:00', $change->end->format(DATE_ATOM));
        self::assertSame(['m-1', 'v1', false], [$change->eventId, $change->expectedVersion, $change->allDay]);
        self::assertSame($start, $change->expectedStart);
        self::assertSame($end, $change->expectedEnd);
    }

    public function testAnAllDayEventMovesKeepingItsNumberOfDays(): void
    {
        $zone   = new DateTimeZone('Europe/Madrid');
        $change = CalendarEventReschedule::toDay('m-2', new DateTimeImmutable('2030-03-05', $zone), new DateTimeImmutable('2030-03-07', $zone), null, true, '2030-03-30', $zone);

        self::assertSame(['2030-03-30', '2030-04-01'], [$change->start->format('Y-m-d'), $change->end->format('Y-m-d')]);
        self::assertTrue($change->allDay);
        self::assertNull($change->expectedVersion);
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function badMoves(): iterable
    {
        yield 'not a day' => ['2030-03-04T09:00:00+00:00', '2030-03-04T10:00:00+00:00', '2030-02-30', false];
        yield 'a timed end before the start' => ['2030-03-04T09:00:00+00:00', '2030-03-04T08:00:00+00:00', '2030-03-05', false];
        yield 'an all-day end before the start' => ['2030-03-05', '2030-03-04', '2030-03-05', true];
    }

    #[DataProvider('badMoves')]
    public function testABadMoveIsRefused(string $start, string $end, string $day, bool $allDay): void
    {
        $this->expectException(InvalidArgumentException::class);
        CalendarEventReschedule::toDay('m', new DateTimeImmutable($start), new DateTimeImmutable($end), null, $allDay, $day, new DateTimeZone('UTC'));
    }

    public function testWhatThePageShowedMatchesTheStoredEventOnlyExactly(): void
    {
        $timed = new CalendarEvent('m', 'T', new DateTimeImmutable('2030-03-04T09:30:00.250+00:00'), new DateTimeImmutable('2030-03-04T10:15:00+00:00'), version: 'v1');
        $shown = static fn (string $start, string $end, bool $allDay = false, ?string $version = 'v1'): CalendarEventShown => new CalendarEventShown('m', new DateTimeImmutable($start), new DateTimeImmutable($end), $allDay, $version, '2030-03-05');

        self::assertTrue($shown('2030-03-04T11:30:00+02:00', '2030-03-04T10:15:00+00:00')->matches($timed), 'the same instants, to the second');
        self::assertSame('2030-03-05', $shown('2030-03-04T23:30:00+00:00', '2030-03-05T00:30:00+00:00')->firstDay(new DateTimeZone('Europe/Madrid')));
        self::assertSame('2030-03-04', $shown('2030-03-04', '2030-03-04', true)->firstDay(new DateTimeZone('Pacific/Kiritimati')));
        self::assertFalse($shown('2030-03-04T09:30:00+00:00', '2030-03-04T11:00:00+00:00')->matches($timed), 'another end');
        self::assertFalse($shown('2030-03-04T09:31:00+00:00', '2030-03-04T10:15:00+00:00')->matches($timed), 'another start');
        self::assertFalse($shown('2030-03-04T09:30:00+00:00', '2030-03-04T10:15:00+00:00', version: null)->matches($timed), 'another version');
        self::assertFalse($shown('2030-03-04', '2030-03-04', true)->matches($timed), 'all-day against timed');
        $allDay = new CalendarEvent('d', 'D', new DateTimeImmutable('2030-03-05'), new DateTimeImmutable('2030-03-06'), allDay: true);
        self::assertTrue($shown('2030-03-05', '2030-03-06', true, null)->matches($allDay));
        self::assertFalse($shown('2030-03-05', '2030-03-09', true, null)->matches($allDay), 'another last day');
        self::assertFalse($shown('2030-03-04', '2030-03-06', true, null)->matches($allDay), 'another first day');
    }

    public function testTheResultsOfAReschedule(): void
    {
        $ok = CalendarEventRescheduleResult::ok('v2');
        self::assertSame([CalendarEventRescheduleStatus::Ok, 'v2', null], [$ok->status, $ok->version, $ok->code]);
        self::assertNull(CalendarEventRescheduleResult::ok()->version);
        self::assertSame([CalendarEventRescheduleStatus::Conflict, 'CALENDAR_EVENT_CHANGED'], [CalendarEventRescheduleResult::conflict()->status, CalendarEventRescheduleResult::conflict()->code]);
        self::assertSame('MEETING_LOCKED', CalendarEventRescheduleResult::conflict('MEETING_LOCKED')->code);
        self::assertSame('CONFLICTS_SEEN', CalendarEventRescheduleResult::conflict('CONFLICTS_SEEN')->code, 'only CONFLICT and CONFLICT_* are reserved');
        self::assertSame([CalendarEventRescheduleStatus::Forbidden, 'CALENDAR_EVENT_FORBIDDEN'], [CalendarEventRescheduleResult::forbidden()->status, CalendarEventRescheduleResult::forbidden()->code]);
        self::assertSame([CalendarEventRescheduleStatus::NotFound, 'CALENDAR_EVENT_NOT_FOUND'], [CalendarEventRescheduleResult::notFound()->status, CalendarEventRescheduleResult::notFound()->code]);
        $lock = CalendarEventRescheduleResult::lockConflict(true, 4);
        self::assertSame([CalendarEventRescheduleStatus::LockConflict, true, 4], [$lock->status, $lock->committed, $lock->retryAfterSeconds]);
        self::assertFalse(CalendarEventRescheduleResult::lockConflict()->committed);
    }

    /**
     * @return iterable<string, array{callable(): CalendarEventRescheduleResult}>
     */
    public static function badResults(): iterable
    {
        yield 'a lock-conflict code as a domain code' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::conflict('CONFLICT_001')];
        yield 'another lock-conflict code' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::forbidden('CONFLICT_17')];
        // Since 2.25.0 (audit UW2-3): the whole CONFLICT family.
        yield 'a CONFLICT_ code with letters' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::conflict('CONFLICT_RULE')];
        yield 'CONFLICT alone' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::forbidden('CONFLICT')];
        yield 'bidi in version' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::ok("v\u{202E}")];
        yield 'lowercase code' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::conflict('changed')];
        yield 'header injection' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::forbidden("NO\r\nX-Evil: 1")];
        yield 'negative retry' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::lockConflict(false, -1)];
        yield 'empty version' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::ok('')];
        yield 'control character in version' => [static fn (): CalendarEventRescheduleResult => CalendarEventRescheduleResult::ok("v\n")];
    }

    /**
     * @param callable(): CalendarEventRescheduleResult $build
     */
    #[DataProvider('badResults')]
    public function testABadResultIsRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }
}
