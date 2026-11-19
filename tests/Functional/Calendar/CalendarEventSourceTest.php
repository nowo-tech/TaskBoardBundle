<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Calendar\CalendarEvent;
use Nowo\TaskBoardBundle\Calendar\CalendarEventCollector;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskCalendarEventEntry;
use Nowo\TaskBoardBundle\Form\CalendarEventMoveFormType;
use Nowo\TaskBoardBundle\Service\TaskCalendarBuilder;
use Nowo\TaskBoardBundle\Tests\Functional\App\ReadOnlyCalendarEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedCalendarEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use stdClass;

use function array_column;
use function array_filter;
use function array_values;

use const DATE_ATOM;

/**
 * Since 2.24.0: timed events of the calendar event sources on a board's calendar. Each source is asked once per page for the
 * viewer and the board's tenant; the page shows what the source returned for that viewer, never another tenant's event, an
 * event out of the page, a value that is not an event or a repeat; every text is escaped; a failing source or a source with
 * a bad key is left out and logged, and the page still renders.
 */
#[CoversClass(CalendarEventCollector::class)]
#[CoversClass(CalendarEvent::class)]
#[CoversClass(CalendarEventRange::class)]
#[CoversClass(TaskCalendarEventEntry::class)]
#[CoversClass(TaskCalendarBuilder::class)]
#[CoversClass(TaskBoardManageController::class)]
#[CoversClass(CalendarEventMoveFormType::class)]
#[CoversClass(TaskBoardExtension::class)]
final class CalendarEventSourceTest extends CalendarTestCase
{
    public function testWithoutEventsNothingOfThemIsDrawn(): void
    {
        $board = $this->board();
        $this->dated($board, 'A task', null, '2030-03-04');
        $this->login($this->owner);

        foreach (['month', 'week', 'list'] as $mode) {
            $this->client->request('GET', $this->calendarUrl($board, 'mode=' . $mode . '&date=2030-03-04'));
            self::assertResponseIsSuccessful();
            $content = (string) $this->client->getResponse()->getContent();
            foreach (['nowo-task-cal__event', 'event-url-value', 'event-csrf-token-value', 'nowo-task-cal-event-move-dialog'] as $marker) {
                self::assertStringNotContainsString($marker, $content, $mode . ': ' . $marker);
            }
        }
        // Each source once per page: three pages.
        self::assertCount(3, $this->events()->calls);
    }

    public function testTheViewersEventsAreDrawnAndEscapedInEveryMode(): void
    {
        $bob   = $this->user('bob@example.com');
        $board = $this->board(tenantKey: 'acme');
        $this->login($this->owner);
        $me            = (string) $this->owner->getId();
        $events        = $this->events();
        $events->store = [
            'm-1' => ['title' => 'Call <b>Acme</b>', 'start' => new DateTimeImmutable('2030-03-04 09:30'), 'end' => new DateTimeImmutable('2030-03-04 10:00'), 'tenant' => 'acme', 'viewers' => [$me], 'movers' => [$me], 'url' => '/crm/meeting/1', 'category' => 'call', 'color' => '#16a34a', 'version' => 3],
            'm-2' => ['title' => 'Fair', 'start' => new DateTimeImmutable('2030-03-05'), 'end' => new DateTimeImmutable('2030-03-06'), 'allDay' => true, 'tenant' => 'acme', 'viewers' => [$me]],
            'm-3' => ['title' => 'Late review', 'start' => new DateTimeImmutable('2030-03-06 22:00'), 'end' => new DateTimeImmutable('2030-03-07 00:00'), 'tenant' => 'acme', 'viewers' => [$me]],
            // Bob's: not this viewer's.
            'm-4' => ['title' => 'Bob only', 'start' => new DateTimeImmutable('2030-03-04 11:00'), 'end' => new DateTimeImmutable('2030-03-04 12:00'), 'tenant' => 'acme', 'viewers' => [(string) $bob->getId()]],
            // Another tenant, and out of the page: dropped by the bundle.
            'm-5' => ['title' => 'Other tenant', 'start' => new DateTimeImmutable('2030-03-04 11:00'), 'end' => new DateTimeImmutable('2030-03-04 12:00'), 'tenant' => 'globex', 'viewers' => [$me]],
            'm-6' => ['title' => 'Next year', 'start' => new DateTimeImmutable('2031-03-04 11:00'), 'end' => new DateTimeImmutable('2031-03-04 12:00'), 'tenant' => 'acme', 'viewers' => [$me]],
        ];
        $events->extra                 = [new stdClass(), new CalendarEvent('m-1', 'Repeat', new DateTimeImmutable('2030-03-04 13:00'), new DateTimeImmutable('2030-03-04 14:00'), tenantKey: 'acme')];
        $this->bookings()->events[$me] = [new CalendarEvent('b-1', 'Room', new DateTimeImmutable('2030-03-04 08:00'), new DateTimeImmutable('2030-03-04 09:00'), readOnly: false, tenantKey: 'acme')];

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=month&date=2030-03-04'));

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $day     = $crawler->filter('[role="gridcell"][data-date="2030-03-04"] [data-testid="nowo-task-cal-event"]');
        // Sorted by time: the booking at 08:00, then the call.
        self::assertSame(['b-1', 'm-1'], $day->each(static fn ($node): string => (string) $node->attr('data-event-id')));
        $call = $day->eq(1);
        self::assertSame('meetings', $call->attr('data-event-source'));
        self::assertStringContainsString('nowo-task-cal__event--call', (string) $call->attr('class'));
        self::assertStringContainsString('is-movable', (string) $call->attr('class'));
        self::assertSame('{"--nowo-task-cal-event-color":"#16a34a"}', $call->attr('data-nowo-task-vars'));
        self::assertSame('Call <b>Acme</b>', $call->filter('a.nowo-task-cal__event-title')->text());
        self::assertSame('/crm/meeting/1', $call->filter('a.nowo-task-cal__event-title')->attr('href'));
        self::assertSame('09:30–10:00', $call->filter('.nowo-task-cal__event-time')->text());
        self::assertSame('3', $call->attr('data-version'));
        self::assertSame('0', $call->attr('data-all-day'));
        self::assertSame((new DateTimeImmutable('2030-03-04 09:30'))->format(DATE_ATOM), $call->attr('data-start'));
        self::assertCount(1, $call->filter('[data-testid="nowo-task-cal-event-move"]'));
        self::assertStringNotContainsString('<b>Acme</b>', $content);
        // The read-only source's event never moves.
        $room = $day->eq(0);
        self::assertNull($room->attr('data-event-movable'));
        self::assertCount(0, $room->filter('button'));
        self::assertStringContainsString('read-only', $room->text());
        // All day, over two days.
        foreach (['2030-03-05', '2030-03-06'] as $date) {
            self::assertSame('All day', $crawler->filter('[data-date="' . $date . '"] [data-event-id="m-2"] .nowo-task-cal__event-time')->text());
        }
        self::assertStringContainsString('is-continuing', (string) $crawler->filter('[data-date="2030-03-05"] [data-event-id="m-2"]')->attr('class'));
        self::assertStringContainsString('is-continued', (string) $crawler->filter('[data-date="2030-03-06"] [data-event-id="m-2"]')->attr('class'));
        // Ends at midnight: on its own day only.
        self::assertCount(1, $crawler->filter('[data-event-id="m-3"]'));
        self::assertSame('22:00–00:00', $crawler->filter('[data-date="2030-03-06"] [data-event-id="m-3"] .nowo-task-cal__event-time')->text());
        foreach (['Bob only', 'Other tenant', 'Next year', 'Repeat'] as $hidden) {
            self::assertStringNotContainsString($hidden, $content, $hidden);
        }
        // The move dialog and its route, as there is a movable event.
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-cal-event-move-dialog"]'));
        self::assertSame('/tools/task-board/board/' . $board->getId() . '/calendar/event-reschedule', $crawler->filter('[data-nowo-task-board-calendar-event-url-value]')->attr('data-nowo-task-board-calendar-event-url-value'));
        self::assertStringNotContainsString('nowo-task-cal__empty', $content, 'a page with events only is not empty');

        // The source got the viewer, the board's tenant and the page's days as instants.
        [$viewer, $range] = $events->calls[0];
        self::assertSame([$me, $board->getId(), 'acme'], [$viewer->userId, $viewer->boardId, $viewer->tenantKey]);
        self::assertSame(['2030-02-25', '2030-03-31'], [$range->days->from, $range->days->to]);
        self::assertSame('2030-02-25 00:00:00', $range->from->format('Y-m-d H:i:s'));
        self::assertSame('2030-04-01 00:00:00', $range->to->format('Y-m-d H:i:s'));
        $drops = array_values(array_filter($this->logger()->records, static fn (array $record): bool => $record['level'] === 'notice' && ($record['context']['source'] ?? null) === 'meetings'));
        self::assertSame(4, $drops[0]['context']['count'] ?? null, 'other tenant, next year, not an event, repeat');

        // Week and list: the same events, on their days.
        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=week&date=2030-03-04'));
        self::assertCount(1, $crawler->filter('[data-date="2030-03-04"] [data-event-id="m-1"]'));
        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=list&date=2030-03-04'));
        self::assertSame(['2030-03-04', '2030-03-05', '2030-03-06'], $crawler->filter('.nowo-task-cal__list-day')->each(static fn ($node): string => (string) $node->attr('data-date')));
        self::assertCount(2, $crawler->filter('#nowo-task-cal-day-2030-03-04 [data-testid="nowo-task-cal-event"]'));
    }

    /** One kernel, never rebooted (worker mode): two viewers in turn each see only their own events. */
    public function testAnotherViewerSeesOnlyTheirOwnEvents(): void
    {
        $bob                   = $this->user('bob@example.com');
        $boards                = ['owner' => $this->board('Owner board'), 'bob' => $this->board('Bob board', creator: $bob)];
        $this->events()->store = [
            'm-1' => ['title' => 'Owner meeting', 'start' => new DateTimeImmutable('2030-03-04 09:30'), 'end' => new DateTimeImmutable('2030-03-04 10:00'), 'viewers' => [(string) $this->owner->getId()]],
            'm-2' => ['title' => 'Bob meeting', 'start' => new DateTimeImmutable('2030-03-04 10:30'), 'end' => new DateTimeImmutable('2030-03-04 11:00'), 'viewers' => [(string) $bob->getId()]],
        ];

        for ($i = 0; $i < 3; ++$i) {
            foreach (['bob' => [$bob, 'Bob meeting', 'Owner meeting'], 'owner' => [$this->owner, 'Owner meeting', 'Bob meeting']] as $name => [$user, $mine, $theirs]) {
                $this->login($user);
                $this->client->request('GET', $this->calendarUrl($boards[$name], 'mode=month&date=2030-03-04'));
                $content = (string) $this->client->getResponse()->getContent();
                self::assertStringContainsString($mine, $content, $name . ' ' . $i);
                self::assertStringNotContainsString($theirs, $content, $name . ' ' . $i);
                // Nothing movable: no dialog, no route.
                self::assertStringNotContainsString('event-url-value', $content);
            }
        }
    }

    public function testABoardTheViewerMayNotOpenAsksNoSource(): void
    {
        $board = $this->board(creator: $this->user('bob@example.com'));
        $this->login($this->user('eve@example.com'));

        $this->client->request('GET', $this->calendarUrl($board));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->events()->calls);
    }

    public function testAFailingSourceIsLeftOutAndLogged(): void
    {
        $board = $this->board();
        $me    = (string) $this->owner->getId();
        $this->login($this->owner);
        $this->events()->failure       = new RuntimeException('backend down');
        $this->bookings()->events[$me] = [new CalendarEvent('b-1', 'Room', new DateTimeImmutable('2030-03-04 08:00'), new DateTimeImmutable('2030-03-04 09:00'))];

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'date=2030-03-04'));

        self::assertResponseIsSuccessful();
        self::assertSame(['b-1'], $crawler->filter('[data-testid="nowo-task-cal-event"]')->each(static fn ($node): string => (string) $node->attr('data-event-id')));
        $warnings = array_values(array_filter($this->logger()->records, static fn (array $record): bool => $record['message'] === 'TaskBoard calendar event source {source} failed: {message}'));
        self::assertCount(1, $warnings);
        self::assertSame('meetings', $warnings[0]['context']['source']);
    }

    public function testASourceWithABadOrTakenKeyIsIgnored(): void
    {
        $board = $this->board();
        $me    = (string) $this->owner->getId();
        $this->login($this->owner);
        $this->events()->store         = ['m-1' => ['title' => 'Meeting', 'start' => new DateTimeImmutable('2030-03-04 09:30'), 'end' => new DateTimeImmutable('2030-03-04 10:00'), 'viewers' => [$me]]];
        $this->bookings()->events[$me] = [new CalendarEvent('b-1', 'Room', new DateTimeImmutable('2030-03-04 08:00'), new DateTimeImmutable('2030-03-04 09:00'))];

        foreach (['Bad Key', 'meetings'] as $key) {
            $this->bookings()->key   = $key;
            $this->logger()->records = [];
            $crawler                 = $this->client->request('GET', $this->calendarUrl($board, 'date=2030-03-04'));
            self::assertSame(['m-1'], $crawler->filter('[data-testid="nowo-task-cal-event"]')->each(static fn ($node): string => (string) $node->attr('data-event-id')), $key);
            self::assertContains('TaskBoard calendar event source {class} has an invalid or repeated key; it is ignored', array_column($this->logger()->records, 'message'), $key);
        }
    }

    public function testMoreEventsThanTheLimitAreCutAndLogged(): void
    {
        self::$taskBoardConfig = ['calendar' => ['enabled' => true, 'events' => ['max_per_source' => 2, 'timezone' => 'UTC']]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        $me    = (string) $this->owner->getId();
        $this->login($this->owner);
        $store = [];
        for ($i = 1; $i <= 6; ++$i) {
            $store['m-' . $i] = ['title' => 'Meeting ' . $i, 'start' => new DateTimeImmutable('2030-03-04 0' . $i . ':00', new DateTimeZone('UTC')), 'end' => new DateTimeImmutable('2030-03-04 0' . $i . ':30', new DateTimeZone('UTC')), 'viewers' => [$me]];
        }
        $this->events()->store = $store;

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=week&date=2030-03-04'));

        self::assertSame(['m-1', 'm-2'], $crawler->filter('[data-testid="nowo-task-cal-event"]')->each(static fn ($node): string => (string) $node->attr('data-event-id')));
        self::assertContains('TaskBoard calendar event source {source} returned more than {max} events; the rest are not shown', array_column($this->logger()->records, 'message'));
        self::assertSame('UTC', $this->events()->calls[0][1]->timezone);

        // The month grid draws three events a day; the rest count in "+n more".
        self::$taskBoardConfig = ['calendar' => ['enabled' => true]];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        $this->login($this->owner);
        foreach ($store as $id => $row) {
            $row['viewers']             = [(string) $this->owner->getId()];
            $this->events()->store[$id] = $row;
        }

        $crawler = $this->client->request('GET', $this->calendarUrl($board, 'mode=month&date=2030-03-04'));

        $day = $crawler->filter('[role="gridcell"][data-date="2030-03-04"]');
        self::assertCount(3, $day->filter('[data-testid="nowo-task-cal-event"]'));
        self::assertSame('+3 more', $day->filter('.nowo-task-cal__more')->text());
    }

    private function events(): ScriptedCalendarEvents
    {
        $events = self::getContainer()->get('app.calendar_events');
        self::assertInstanceOf(ScriptedCalendarEvents::class, $events);

        return $events;
    }

    private function bookings(): ReadOnlyCalendarEvents
    {
        $bookings = self::getContainer()->get('app.calendar_bookings');
        self::assertInstanceOf(ReadOnlyCalendarEvents::class, $bookings);

        return $bookings;
    }
}
