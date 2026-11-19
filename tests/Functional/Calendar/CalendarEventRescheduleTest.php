<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarEventCollector;
use Nowo\TaskBoardBundle\Calendar\CalendarEventReschedule;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRescheduleResult;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\CalendarEventMoveFormType;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedCalendarEvents;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function array_column;
use function json_decode;
use function str_repeat;

/**
 * Since 2.24.0: the reschedule route of the calendar events. The bundle checks the user, the CSRF token and the board; the
 * source answers for the event: moved (200, same time of day and duration), changed meanwhile (409 with the domain code in
 * X-Error-Code), not allowed (403), not found or another tenant (404), and a lock conflict maps to REQ-RUNTIME-003 rule 6
 * (409 + Retry-After + X-Conflict-Code CONFLICT_001; CONFLICT_002 without Retry-After after a partial commit).
 */
#[CoversClass(TaskBoardManageController::class)]
#[CoversClass(CalendarEventMoveFormType::class)]
#[CoversClass(CalendarEventCollector::class)]
#[CoversClass(CalendarEventReschedule::class)]
#[CoversClass(CalendarEventRescheduleResult::class)]
final class CalendarEventRescheduleTest extends CalendarTestCase
{
    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->board           = $this->board(tenantKey: 'acme');
        $me                    = (string) $this->owner->getId();
        $this->events()->store = [
            'm-1' => ['title' => 'Call', 'start' => new DateTimeImmutable('2030-03-04 09:30'), 'end' => new DateTimeImmutable('2030-03-04 10:15'), 'tenant' => 'acme', 'viewers' => [$me], 'movers' => [$me], 'version' => 3],
            'm-2' => ['title' => 'Fair', 'start' => new DateTimeImmutable('2030-03-05'), 'end' => new DateTimeImmutable('2030-03-06'), 'allDay' => true, 'tenant' => 'acme', 'viewers' => [$me], 'movers' => [$me]],
            'm-3' => ['title' => 'Read only', 'start' => new DateTimeImmutable('2030-03-04 11:00'), 'end' => new DateTimeImmutable('2030-03-04 12:00'), 'tenant' => 'acme', 'viewers' => [$me]],
            'm-4' => ['title' => 'Other tenant', 'start' => new DateTimeImmutable('2030-03-04 11:00'), 'end' => new DateTimeImmutable('2030-03-04 12:00'), 'tenant' => 'globex', 'viewers' => [$me], 'movers' => [$me]],
        ];
        $this->login($this->owner);
    }

    public function testATimedEventMovesKeepingItsTimeAndDuration(): void
    {
        $answer = $this->move(['id' => 'm-1', 'date' => '2030-03-07', 'expected_start' => $this->atom('2030-03-04 09:30'), 'expected_end' => $this->atom('2030-03-04 10:15'), 'version' => '3']);

        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Event moved to 2030-03-07', 'start' => $this->atom('2030-03-07 09:30'), 'end' => $this->atom('2030-03-07 10:15'), 'version' => '4'], $answer);
        $row = $this->events()->store['m-1'];
        self::assertEquals(new DateTimeImmutable('2030-03-07 09:30'), $row['start']);
        self::assertSame(4, $row['version'] ?? null);
        [$viewer, $change] = $this->events()->moves[0];
        self::assertSame([(string) $this->owner->getId(), $this->board->getId(), 'acme'], [$viewer->userId, $viewer->boardId, $viewer->tenantKey]);
        self::assertSame(['m-1', '3', false], [$change->eventId, $change->expectedVersion, $change->allDay]);
        self::assertContains('TaskBoard write: {action}', array_column($this->logger()->records, 'message'));

        // The page shows it on its new day, with its new version.
        $crawler = $this->client->request('GET', $this->calendarUrl($this->board, 'mode=week&date=2030-03-04'));
        self::assertSame('4', $crawler->filter('[data-date="2030-03-07"] [data-event-id="m-1"]')->attr('data-version'));
    }

    public function testAnAllDayEventKeepsItsDays(): void
    {
        $answer = $this->move(['id' => 'm-2', 'date' => '2030-03-10', 'expected_start' => '2030-03-05', 'expected_end' => '2030-03-06', 'all_day' => '1', 'version' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['2030-03-10', '2030-03-11'], [$answer['start'] ?? null, $answer['end'] ?? null]);
        self::assertTrue($this->events()->moves[0][1]->allDay);
    }

    public function testAnEventThatChangedMeanwhileIsAConflictWithItsDomainCode(): void
    {
        $answer = $this->move(['id' => 'm-1', 'date' => '2030-03-07', 'expected_start' => $this->atom('2030-03-04 09:00'), 'expected_end' => $this->atom('2030-03-04 10:15'), 'version' => '3']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(CalendarEventRescheduleResult::CODE_CHANGED, $answer['code'] ?? null);
        self::assertResponseHeaderSame('X-Error-Code', 'CALENDAR_EVENT_CHANGED');
        self::assertResponseNotHasHeader('X-Conflict-Code');
        self::assertResponseNotHasHeader('Retry-After');
        self::assertEquals(new DateTimeImmutable('2030-03-04 09:30'), $this->events()->store['m-1']['start'], 'nothing written');

        // A stale version too.
        $this->move(['id' => 'm-1', 'date' => '2030-03-07', 'expected_start' => $this->atom('2030-03-04 09:30'), 'expected_end' => $this->atom('2030-03-04 10:15'), 'version' => '2']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAnEventTheViewerMayNotMoveIsForbidden(): void
    {
        $answer = $this->move(['id' => 'm-3', 'date' => '2030-03-07', 'expected_start' => $this->atom('2030-03-04 11:00'), 'expected_end' => $this->atom('2030-03-04 12:00'), 'version' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => 'You may not move this event.', 'code' => 'CALENDAR_EVENT_FORBIDDEN'], $answer);
        self::assertResponseHeaderSame('X-Error-Code', 'CALENDAR_EVENT_FORBIDDEN');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notFound(): iterable
    {
        yield 'another tenant' => [['id' => 'm-4']];
        yield 'unknown id' => [['id' => 'nope']];
        yield 'unknown source' => [['source' => 'unknown']];
        yield 'a read-only source' => [['source' => 'bookings']];
        yield 'a key that only looks the same' => [['source' => 'Meetings']];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('notFound')]
    public function testAnEventOutOfReachIsNotFound(array $fields): void
    {
        $answer = $this->move($fields + ['id' => 'm-1', 'date' => '2030-03-07', 'expected_start' => $this->atom('2030-03-04 11:00'), 'expected_end' => $this->atom('2030-03-04 12:00'), 'version' => '1']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => 'This event is no longer available.', 'code' => 'CALENDAR_EVENT_NOT_FOUND'], $answer);
        self::assertResponseHeaderSame('X-Error-Code', 'CALENDAR_EVENT_NOT_FOUND');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function malformed(): iterable
    {
        yield 'no day' => [['date' => '']];
        yield 'not a day' => [['date' => '2030-02-30']];
        yield 'out of the calendar years' => [['date' => '2300-01-01']];
        yield 'no expected start' => [['expected_start' => '']];
        yield 'an expected start without offset' => [['expected_start' => '2030-03-04T09:30:00']];
        yield 'an end before the start' => [['expected_end' => '2030-03-04T08:00:00+00:00']];
        yield 'an all-day end before the start' => [['all_day' => '1', 'expected_start' => '2030-03-05', 'expected_end' => '2030-03-04']];
        yield 'no id' => [['id' => '']];
        yield 'a control character' => [['id' => "m-1\n"]];
        yield 'a long version' => [['version' => str_repeat('v', 129)]];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('malformed')]
    public function testAMalformedMoveIsABadRequest(array $fields): void
    {
        $answer = $this->move($fields + ['id' => 'm-1', 'date' => '2030-03-07', 'expected_start' => '2030-03-04T09:30:00+00:00', 'expected_end' => '2030-03-04T10:15:00+00:00', 'version' => '3']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('CALENDAR_EVENT_INVALID', $answer['code'] ?? null);
        self::assertSame([], $this->events()->moves);
    }

    public function testALockConflictAnswersConflict001WithRetryAfter(): void
    {
        $this->events()->answer = CalendarEventRescheduleResult::lockConflict(retryAfterSeconds: 3);
        $answer                 = $this->validMove();

        self::assertResponseStatusCodeSame(409);
        self::assertSame('CONFLICT_001', $answer['code'] ?? null);
        self::assertResponseHeaderSame('X-Conflict-Code', 'CONFLICT_001');
        self::assertResponseHeaderSame('Retry-After', '3');
        self::assertResponseNotHasHeader('X-Error-Code');
    }

    public function testAPartialCommitAnswersConflict002WithoutRetryAfter(): void
    {
        $this->events()->answer = CalendarEventRescheduleResult::lockConflict(committed: true);
        $answer                 = $this->validMove();

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['message' => 'Part of the change may have been saved: reload the calendar before trying again.', 'code' => 'CONFLICT_002'], $answer);
        self::assertResponseHeaderSame('X-Conflict-Code', 'CONFLICT_002');
        self::assertResponseNotHasHeader('Retry-After');
    }

    public function testAThrownLockConflictIsMappedFromTheExceptionChain(): void
    {
        $this->events()->rescheduleFailure = new RuntimeException('wrapped', 0, new TransientStorageFailure(2));
        $this->validMove();
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('X-Conflict-Code', 'CONFLICT_001');
        self::assertResponseHeaderSame('Retry-After', '2');

        $this->events()->rescheduleFailure = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, null, new RuntimeException('lock'), true);
        $this->validMove();
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('X-Conflict-Code', 'CONFLICT_002');
        self::assertResponseNotHasHeader('Retry-After');
    }

    public function testAnyOtherFailureIsNotAConflict(): void
    {
        $this->events()->rescheduleFailure = new RuntimeException('broken');
        $this->client->catchExceptions(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broken');
        $this->validMove();
    }

    public function testAnInvalidTokenOrABoardOutOfReachNeverAsksTheSource(): void
    {
        $this->move(['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $this->login($this->user('eve@example.com'));
        $this->validMove();
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->events()->moves);
    }

    /**
     * @return array<string, mixed>
     */
    private function validMove(): array
    {
        return $this->move(['id' => 'm-1', 'date' => '2030-03-07', 'expected_start' => $this->atom('2030-03-04 09:30'), 'expected_end' => $this->atom('2030-03-04 10:15'), 'version' => '3']);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function move(array $fields): array
    {
        $this->client->request('POST', '/tools/task-board/board/' . $this->board->getId() . '/calendar/event-reschedule', $fields + [
            'source'  => 'meetings',
            'all_day' => '0',
            '_token'  => PredictableCsrfTokenManager::value(CalendarEventMoveFormType::CSRF_TOKEN_ID),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
        /** @var array<string, mixed> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];

        return $answer;
    }

    private function atom(string $local): string
    {
        return (new DateTimeImmutable($local))->format(DateTimeInterface::ATOM);
    }

    private function events(): ScriptedCalendarEvents
    {
        $events = self::getContainer()->get('app.calendar_events');
        self::assertInstanceOf(ScriptedCalendarEvents::class, $events);

        return $events;
    }
}
