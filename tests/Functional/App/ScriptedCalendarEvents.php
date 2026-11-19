<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Calendar\CalendarEvent;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEventReschedule;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRescheduleResult;
use Nowo\TaskBoardBundle\Calendar\CalendarEventReschedulerInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use Throwable;

use function in_array;

/**
 * Since 2.24.0: a calendar event source of the functional tests (autoconfigured), with a store as a real source keeps one.
 * `events()` returns the rows a viewer may read (`viewers`) and, to prove the bundle's own filters, does NOT filter by tenant
 * or range; `extra` is yielded as given (values that are not events, repeats). `reschedule()` behaves as a careful source:
 * other tenant or unknown id = not found, not a mover = forbidden, another start, end or version = conflict, else the move.
 */
final class ScriptedCalendarEvents implements CalendarEventReschedulerInterface
{
    public string $key = 'meetings';

    /**
     * @var array<string, array{title: string, start: DateTimeImmutable, end: DateTimeImmutable, allDay?: bool, version?: int, tenant?: ?string, viewers: list<string>, movers?: list<string>, url?: ?string, category?: ?string, color?: ?string}>
     */
    public array $store = [];

    /** @var list<mixed> */
    public array $extra = [];

    /** @var list<array{CalendarViewer, CalendarEventRange}> */
    public array $calls = [];

    /** @var list<array{CalendarViewer, CalendarEventReschedule}> */
    public array $moves = [];

    public ?Throwable $failure = null;

    /** Answered by reschedule() instead of the store's answer when set. */
    public ?CalendarEventRescheduleResult $answer = null;

    public ?Throwable $rescheduleFailure = null;

    public function key(): string
    {
        return $this->key;
    }

    public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable
    {
        $this->calls[] = [$viewer, $range];
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
        foreach ($this->store as $id => $row) {
            if (!in_array($viewer->userId, $row['viewers'], true)) {
                continue;
            }

            yield new CalendarEvent(
                (string) $id,
                $row['title'],
                $row['start'],
                $row['end'],
                allDay: $row['allDay'] ?? false,
                url: $row['url'] ?? null,
                color: $row['color'] ?? null,
                category: $row['category'] ?? null,
                readOnly: !in_array($viewer->userId, $row['movers'] ?? [], true),
                version: (string) ($row['version'] ?? 1),
                tenantKey: $row['tenant'] ?? null,
            );
        }

        yield from $this->extra;
    }

    public function reschedule(CalendarViewer $viewer, CalendarEventReschedule $change): CalendarEventRescheduleResult
    {
        $this->moves[] = [$viewer, $change];
        if ($this->rescheduleFailure instanceof Throwable) {
            throw $this->rescheduleFailure;
        }
        if ($this->answer instanceof CalendarEventRescheduleResult) {
            return $this->answer;
        }
        $row = $this->store[$change->eventId] ?? null;
        if ($row === null || ($row['tenant'] ?? null) !== $viewer->tenantKey || !in_array($viewer->userId, $row['viewers'], true)) {
            return CalendarEventRescheduleResult::notFound();
        }
        if (!in_array($viewer->userId, $row['movers'] ?? [], true)) {
            return CalendarEventRescheduleResult::forbidden();
        }
        $version = $row['version'] ?? 1;
        $same    = $row['allDay'] ?? false
            ? $row['start']->format('Y-m-d') === $change->expectedStart->format('Y-m-d') && $row['end']->format('Y-m-d') === $change->expectedEnd->format('Y-m-d')
            : $row['start'] == $change->expectedStart && $row['end'] == $change->expectedEnd;
        if (!$same || (string) $version !== $change->expectedVersion) {
            return CalendarEventRescheduleResult::conflict();
        }
        $this->store[$change->eventId] = ['start' => $change->start, 'end' => $change->end, 'version' => $version + 1] + $row;

        return CalendarEventRescheduleResult::ok((string) ($version + 1));
    }
}
