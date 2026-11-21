<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Doctrine;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;

/**
 * Since 2.29.0 (REQ-ARCH-001 rule 7, DDD-host checklist B4; opt-in `events.after_host_commit: true`): the change events
 * of the bundle's write services ({@see self::DEFERRED}) after the **application's** commit.
 *
 * {@see TaskEventDeferral} already holds the events of a bundle write until that write's own commit. When the
 * application wrapped the bundle call in its own transaction, that commit was only a savepoint, so this relay (the
 * deferral's dispatcher) **holds** the event:
 *
 * - the outermost commit of the connection releases it ({@see PrivateEntityManagerConnection});
 * - a rollback of the transaction drops it; a rollback to a savepoint drops the events held at that nesting level or
 *   deeper (all of them when the level cannot be told); a released savepoint moves its events one level down;
 * - released events are dispatched at the first idle point: the next bundle event outside a transaction, the main
 *   request's `kernel.response`, or the end of the request / message / command. Never inside the driver's commit.
 *
 * Without an open transaction an event is dispatched at once, as before. A listener that throws for a released event is
 * logged (event name and exception class) and the others still run: the data is committed.
 *
 * Registered only with `database.private_entity_manager: true` and doctrine/dbal 4 (the commit is seen through the
 * bundle's DBAL middleware on that connection); otherwise every event is dispatched at once (2.28 behaviour).
 *
 * FrankenPHP worker mode without kernel reset: held events are request state. Every boundary dispatches what was
 * released and drops what is still held (a warning: that transaction never committed); {@see reset()} does the same at
 * `kernel.reset`. Nothing survives into the next request.
 *
 * @internal
 */
final class HostCommitEventRelay implements EventDispatcherInterface, EventSubscriberInterface, ResetInterface
{
    /** The change events that wait for the application's commit; checks, queries and vetoes always pass through. */
    public const DEFERRED = [
        TaskBoardEvents::TASK_CREATED,
        TaskBoardEvents::TASK_UPDATED,
        TaskBoardEvents::TASK_MOVED,
        TaskBoardEvents::TASK_STATUS_CHANGED,
        TaskBoardEvents::TASK_COMPLETED,
        TaskBoardEvents::TASK_REOPENED,
        TaskBoardEvents::TASK_ARCHIVED,
        TaskBoardEvents::TASK_RESTORED,
        TaskBoardEvents::TASK_ERASED,
        TaskBoardEvents::TASK_BOARD_CHANGED,
        TaskBoardEvents::TASK_MEMBER_ADDED,
        TaskBoardEvents::TASK_MEMBER_REMOVED,
        TaskBoardEvents::TASK_COMMENT,
        TaskBoardEvents::TASK_COMMENT_DELETED,
        TaskBoardEvents::TASK_MENTION,
        TaskBoardEvents::TASK_TIME_LOGGED,
        TaskBoardEvents::TASK_TIME_REMOVED,
        TaskBoardEvents::TASK_TIME_BILLABLE_CHANGED,
        TaskBoardEvents::TASK_DEPENDENCY_ADDED,
        TaskBoardEvents::TASK_DEPENDENCY_REMOVED,
        TaskBoardEvents::TASK_LINK_ADDED,
        TaskBoardEvents::TASK_LINK_UPDATED,
        TaskBoardEvents::TASK_LINK_REMOVED,
        TaskBoardEvents::COLUMN_CREATED,
        TaskBoardEvents::COLUMN_UPDATED,
        TaskBoardEvents::COLUMN_DELETED,
        TaskBoardEvents::COLUMNS_REORDERED,
    ];

    /** @var list<array{event: object, name: string, level: int}> held until the outermost commit */
    private array $held = [];

    /** @var list<array{event: object, name: string}> committed, waiting for an idle connection */
    private array $released = [];

    private bool $releasing = false;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly TaskBoardEntityManager $ownManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Late, so the application's own response listeners ran (and committed) first.
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $name = $eventName ?? $event::class;
        if (!in_array($name, self::DEFERRED, true)) {
            return $this->dispatcher->dispatch($event, $eventName);
        }

        $level = $this->openLevel();
        if ($level === 0) {
            $this->dispatchReleased();

            return $this->dispatcher->dispatch($event, $eventName);
        }

        // @igor-ignore - Held for the running unit of work only: released or dropped at its boundary and by reset().
        $this->held[] = ['event' => $event, 'name' => $name, 'level' => $level];

        return $event;
    }

    /** The main request's response is ready: a committed application transaction's events are dispatched. */
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->dispatchReleased();
        }
    }

    /** The outermost transaction of the connection committed: every held event is released. */
    public function connectionCommitted(): void
    {
        foreach ($this->held as $item) {
            // @igor-ignore - Held for the running unit of work only (see dispatch()).
            $this->released[] = ['event' => $item['event'], 'name' => $item['name']];
        }
        // @igor-ignore - Held for the running unit of work only (see dispatch()).
        $this->held = [];
    }

    /**
     * A transaction (`$level` 1 or null) or a savepoint of nesting level `$level` was rolled back: the events held at that
     * level or deeper are dropped (all of them when the level is unknown).
     */
    public function connectionRolledBack(?int $level): void
    {
        // @igor-ignore - Held for the running unit of work only (see dispatch()).
        $this->held = $level === null || $level <= 1
            ? []
            : array_values(array_filter($this->held, static fn (array $item): bool => $item['level'] < $level));
    }

    /** A savepoint of this level was released: its events now belong to the level below. */
    public function savepointReleased(int $level): void
    {
        foreach ($this->held as $index => $item) {
            if ($item['level'] === $level && $level > 1) {
                // @igor-ignore - Held for the running unit of work only (see dispatch()).
                $this->held[$index]['level'] = $level - 1;
            }
        }
    }

    /**
     * Number of held (not yet committed) and released (not yet dispatched) events.
     *
     * @return array{held: int, released: int}
     */
    public function pending(): array
    {
        return ['held' => count($this->held), 'released' => count($this->released)];
    }

    /**
     * A unit-of-work boundary or `kernel.reset`: released events are dispatched, held ones (their transaction never
     * committed) are dropped with a warning. Nothing is kept afterwards.
     */
    public function reset(): void
    {
        try {
            $this->dispatchReleased(true);
        } finally {
            $dropped = count($this->held) + count($this->released);
            // @igor-ignore - Boundary drop of request state.
            $this->held = [];
            // @igor-ignore - Boundary drop of request state.
            $this->released = [];
            if ($dropped > 0) {
                $this->logger?->warning('nowo_task_board: dropped {count} task event(s) of a transaction that did not commit before the end of the unit of work.', ['count' => $dropped]);
            }
        }
    }

    private function dispatchReleased(bool $evenInTransaction = false): void
    {
        if ($this->released === [] || $this->releasing || (!$evenInTransaction && $this->openLevel() !== 0)) {
            return;
        }

        // @igor-ignore - Re-entrance guard of the running dispatch only.
        $this->releasing = true;
        try {
            while ($this->released !== []) {
                $item = $this->released[0];
                // @igor-ignore - Held for the running unit of work only (see dispatch()).
                $this->released = array_slice($this->released, 1);
                try {
                    $this->dispatcher->dispatch($item['event'], $item['name']);
                } catch (Throwable $exception) {
                    $this->logger?->error('nowo_task_board: a {event} listener failed after the change was committed.', ['event' => $item['name'], 'exception' => $exception::class]);
                }
            }
        } finally {
            // @igor-ignore - Re-entrance guard of the running dispatch only.
            $this->releasing = false;
        }
    }

    /** The transaction nesting level of the bundle's connection (0 when none is open or it cannot be read). */
    private function openLevel(): int
    {
        try {
            return $this->ownManager->source()->getConnection()->getTransactionNestingLevel();
        } catch (Throwable) {
            return 0;
        }
    }
}
