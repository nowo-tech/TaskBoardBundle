<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEvent;
use Nowo\TaskBoardBundle\Calendar\CalendarEventCollector;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEventReschedule;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRescheduleResult;
use Nowo\TaskBoardBundle\Calendar\CalendarEventReschedulerInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarEventSourceInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use Nowo\TaskBoardBundle\Dto\TaskCalendarEventEntry;
use Nowo\TaskBoardBundle\Tests\Stub\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function date_default_timezone_get;
use function date_default_timezone_set;

/**
 * Since 2.24.0: the collector turns the sources' events into what a page draws, in the calendar's timezone, sorted, and finds
 * the source of a reschedule by its exact key. It keeps nothing between calls (FrankenPHP worker mode).
 */
#[CoversClass(CalendarEventCollector::class)]
#[CoversClass(TaskCalendarEventEntry::class)]
final class CalendarEventCollectorTest extends TestCase
{
    private string $defaultZone;

    protected function setUp(): void
    {
        $this->defaultZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultZone);
    }

    public function testEventsAreConvertedToTheCalendarTimezoneAndSorted(): void
    {
        $utc    = new DateTimeZone('UTC');
        $source = $this->source('crm', [
            new CalendarEvent('late', 'Late', new DateTimeImmutable('2030-03-04 22:30', $utc), new DateTimeImmutable('2030-03-04 23:30', $utc), readOnly: false),
            new CalendarEvent('early', 'Early', new DateTimeImmutable('2030-03-04 07:00', $utc), new DateTimeImmutable('2030-03-04 08:00', $utc)),
            new CalendarEvent('day', 'Day', new DateTimeImmutable('2030-03-05'), new DateTimeImmutable('2030-03-05'), allDay: true, readOnly: false),
        ], true);
        $collector = new CalendarEventCollector([$source], timezone: 'Europe/Madrid');

        $entries = $collector->collect(new CalendarViewer('u1', 'b1'), new CalendarDateRange('2030-03-04', '2030-03-10'));

        self::assertSame(['early', 'late', 'day'], array_map(static fn (TaskCalendarEventEntry $entry): string => $entry->event->id, $entries));
        // 22:30 UTC is 23:30 in Madrid on the 4th; it ends at 00:30 on the 5th.
        $late = $entries[1];
        self::assertSame(['2030-03-04', '2030-03-05', '23:30', '00:30', '2030-03-04T23:30:00+01:00', '2030-03-05T00:30:00+01:00', true, 'crm'], [$late->first, $late->last, $late->startTime, $late->endTime, $late->startValue, $late->endValue, $late->movable, $late->source]);
        self::assertTrue($late->spansSeveralDays());
        self::assertFalse($entries[0]->movable, 'read-only');
        self::assertSame(['2030-03-05', '2030-03-05', null, null, '2030-03-05', '2030-03-05'], [$entries[2]->first, $entries[2]->last, $entries[2]->startTime, $entries[2]->endTime, $entries[2]->startValue, $entries[2]->endValue]);
        self::assertFalse($entries[2]->spansSeveralDays());
        self::assertSame('Europe/Madrid', $source->ranges[0]->timezone);
    }

    public function testWithoutATimezoneThePhpDefaultIsReadPerCall(): void
    {
        $collector = new CalendarEventCollector([]);
        date_default_timezone_set('America/New_York');
        self::assertSame('America/New_York', $collector->timezone()->getName());
        date_default_timezone_set('Asia/Tokyo');
        self::assertSame('Asia/Tokyo', $collector->timezone()->getName());
        self::assertSame([], $collector->collect(new CalendarViewer('u1', 'b1'), new CalendarDateRange('2030-03-04', '2030-03-10')));
    }

    public function testTheReschedulerIsFoundByItsExactKeyOnly(): void
    {
        $reader    = $this->source('bookings', [], false);
        $mover     = $this->source('crm', [], true);
        $numeric   = $this->source('123', [], true);
        $twin      = $this->source('crm', [], true);
        $collector = new CalendarEventCollector([$reader, $mover, $numeric, $twin]);

        self::assertSame($mover, $collector->rescheduler('crm'));
        self::assertSame($numeric, $collector->rescheduler('123'));
        self::assertNull($collector->rescheduler('bookings'), 'a source that cannot reschedule');
        self::assertNull($collector->rescheduler('CRM'));
        self::assertNull($collector->rescheduler('crm '));
        self::assertNull($collector->rescheduler(''));
    }

    /** Audit UW-TB-3: an endless source of dropped values (or of events) never holds the request. */
    public function testAnEndlessSourceIsCut(): void
    {
        $range  = new CalendarDateRange('2030-03-04', '2030-03-10');
        $viewer = new CalendarViewer('u1', 'b1', 'acme');
        foreach ([
            'other tenant, forever' => static fn (): CalendarEvent => new CalendarEvent('e', 'T', new DateTimeImmutable('2030-03-04 09:00'), new DateTimeImmutable('2030-03-04 10:00'), tenantKey: 'globex'),
            'not events, forever'   => static fn (): string => 'junk',
        ] as $case => $make) {
            $logger    = new RecordingLogger();
            $collector = new CalendarEventCollector([$this->endless('crm', $make)], $logger, 'UTC', 10);

            self::assertSame([], $collector->collect($viewer, $range), $case);
            self::assertContains('TaskBoard calendar event source {source} returned too many values; the rest are not read', array_column($logger->records, 'message'), $case);
            self::assertNull($collector->find($viewer, 'crm', 'e', $range), $case);
        }

        // Valid events, one id each: the kept limit cuts first, and at most four times the limit is ever pulled.
        $count     = 0;
        $logger    = new RecordingLogger();
        $collector = new CalendarEventCollector([$this->endless('crm', static function () use (&$count): CalendarEvent {
            ++$count;

            return new CalendarEvent('e' . $count, 'T', new DateTimeImmutable('2030-03-04 09:00'), new DateTimeImmutable('2030-03-04 10:00'), tenantKey: 'acme');
        })], $logger, 'UTC', 10);
        self::assertCount(10, $collector->collect($viewer, $range));
        self::assertLessThanOrEqual(40, $count);
        self::assertContains('TaskBoard calendar event source {source} returned more than {max} events; the rest are not shown', array_column($logger->records, 'message'));
    }

    public function testFindReturnsTheEventOfThatSourceAndIdOnly(): void
    {
        $event     = new CalendarEvent('m-1', 'Meeting', new DateTimeImmutable('2030-03-04 09:00'), new DateTimeImmutable('2030-03-04 10:00'));
        $collector = new CalendarEventCollector([$this->source('bookings', [$event], false), $this->source('crm', [$event], true)], timezone: 'UTC');
        $range     = new CalendarDateRange('2030-03-04', '2030-03-04');
        $viewer    = new CalendarViewer('u1', 'b1');

        self::assertSame($event, $collector->find($viewer, 'crm', 'm-1', $range));
        self::assertNull($collector->find($viewer, 'crm', 'm-2', $range));
        self::assertNull($collector->find($viewer, 'nope', 'm-1', $range));
        self::assertNull($collector->find($viewer, 'crm', 'm-1', new CalendarDateRange('2030-03-05', '2030-03-05')), 'not on that day');
    }

    /**
     * Since 2.25.0 (audit UW2-4): a source that ignores the range returns other days first; on the move re-read they do not
     * count toward the drop cap, so the event is still found, while the page keeps counting them.
     */
    public function testFindSkipsOutOfRangeEventsWithoutCountingThemAsDrops(): void
    {
        $events = [];
        for ($i = 0; $i < 15; ++$i) {
            $events[] = new CalendarEvent('other-' . $i, 'Other', new DateTimeImmutable('2030-03-20 09:00'), new DateTimeImmutable('2030-03-20 10:00'));
        }
        $events[]  = new CalendarEvent('m-1', 'Meeting', new DateTimeImmutable('2030-03-04 09:00'), new DateTimeImmutable('2030-03-04 10:00'));
        $logger    = new RecordingLogger();
        $collector = new CalendarEventCollector([$this->source('crm', $events, true)], $logger, 'UTC', 10);
        $range     = new CalendarDateRange('2030-03-04', '2030-03-04');
        $viewer    = new CalendarViewer('u1', 'b1');

        self::assertSame('m-1', $collector->find($viewer, 'crm', 'm-1', $range)?->id);
        self::assertSame([], $logger->records, 'skipped, not dropped');
        self::assertSame([], $collector->collect($viewer, $range), 'the page stops after ten drops');
        self::assertContains('TaskBoard calendar event source {source} returned too many values; the rest are not read', array_column($logger->records, 'message'));
    }

    /**
     * @param callable(): mixed $make
     */
    private function endless(string $key, callable $make): CalendarEventSourceInterface
    {
        return new class($key, $make) implements CalendarEventSourceInterface {
            /** @var callable(): mixed */
            private $make;

            public function __construct(private readonly string $key, callable $make)
            {
                $this->make = $make;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable
            {
                while (true) {
                    yield ($this->make)();
                }
            }
        };
    }

    /**
     * @param list<CalendarEvent> $events
     */
    private function source(string $key, array $events, bool $reschedules): CalendarEventSourceInterface
    {
        return $reschedules ? new class($key, $events) implements CalendarEventReschedulerInterface {
            /** @var list<CalendarEventRange> */
            public array $ranges = [];

            /**
             * @param list<CalendarEvent> $events
             */
            public function __construct(private readonly string $key, private readonly array $events)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable
            {
                $this->ranges[] = $range;

                return $this->events;
            }

            public function reschedule(CalendarViewer $viewer, CalendarEventReschedule $change): CalendarEventRescheduleResult
            {
                return CalendarEventRescheduleResult::ok();
            }
        } : new class($key, $events) implements CalendarEventSourceInterface {
            /** @var list<CalendarEventRange> */
            public array $ranges = [];

            /**
             * @param list<CalendarEvent> $events
             */
            public function __construct(private readonly string $key, private readonly array $events)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable
            {
                $this->ranges[] = $range;

                return $this->events;
            }
        };
    }
}
