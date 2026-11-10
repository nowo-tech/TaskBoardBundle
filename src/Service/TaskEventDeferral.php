<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Since 2.3.0 (audit IC-I3): the event dispatcher of `TaskManager` and `TaskMemberAssigner`. Outside {@see self::defer()}
 * it passes every event straight on, as before. Inside, it holds the task events of a write made of several steps (a copy,
 * an occurrence) and dispatches them once the call returns, after its commit; when the call fails they are dropped, so no
 * listener acts on rows a rollback removed. Calls nest; the outermost one dispatches.
 */
final class TaskEventDeferral implements EventDispatcherInterface, ResetInterface
{
    private int $depth = 0;

    /** @var list<array{0: object, 1: string|null}> */
    private array $queue = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        // Since 2.10.0 (audit JY-L1): where a listener's failure goes when the write it follows is already committed.
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if ($this->depth > 0) {
            // @igor-ignore - Held for the running call only: emptied when it ends, and by reset() between worker requests.
            $this->queue[] = [$event, $eventName];

            return $event;
        }

        return $this->dispatcher->dispatch($event, $eventName);
    }

    /**
     * Since 2.11.0 (audit KO-T-L2): for an event of a write that is already saved. Inside {@see self::begin()} /
     * {@see self::defer()} it is held like any other; otherwise it is dispatched at once and a listener's failure is
     * logged as an error, not rethrown, so a saved write is never reported as failed (and a retry does not repeat it).
     */
    public function dispatchSaved(object $event, string $eventName): void
    {
        if ($this->depth > 0) {
            // @igor-ignore - Held for the running call only: emptied when it ends, and by reset() between worker requests.
            $this->queue[] = [$event, $eventName];

            return;
        }
        try {
            $this->dispatcher->dispatch($event, $eventName);
        } catch (Throwable $exception) {
            $this->logger->error('TaskBoard: a listener failed after the write was committed', ['bundle' => 'nowo_task_board', 'event' => $eventName, 'exception' => $exception]);
        }
    }

    /**
     * Runs `$write` holding the events it dispatches; dispatches them after it returns, or drops them if it throws.
     * Since 2.10.1 (audit JY-I6): with `$rethrow` false, a listener's failure after the dispatch is logged, not rethrown
     * (see {@see self::commit()}): for a `$write` that commits its own transaction, so a saved copy is never reported as failed.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function defer(callable $write, bool $rethrow = true): mixed
    {
        $this->begin();
        try {
            $result = $write();
        } catch (Throwable $failure) {
            $this->rollBack();

            throw $failure;
        }
        $this->commit($rethrow);

        return $result;
    }

    /**
     * Since 2.10.0: holds the events from now on, for a write that opens its own transaction (a sync page, an import);
     * pair it with {@see self::commit()} after the commit, or {@see self::rollBack()} after a rollback. Nests with defer().
     */
    public function begin(): void
    {
        // @igor-ignore - Scoped to the caller's transaction, and cleared by reset() between worker requests.
        ++$this->depth;
    }

    /**
     * Since 2.10.0: the transaction of {@see self::begin()} committed: the outermost one dispatches what it held. Every held
     * event is dispatched even when a listener throws (audit JY-L1); then the first failure is rethrown, or, with
     * `$rethrow` false (the caller's write is committed and must not be reported as failed), logged as an error.
     */
    public function commit(bool $rethrow = true): void
    {
        if ($this->depth === 0) {
            return;
        }
        // @igor-ignore - Scoped to the caller's transaction (see begin()).
        --$this->depth;
        if ($this->depth === 0) {
            $queue = $this->queue;
            // @igor-ignore - Scoped to the caller's transaction (see begin()).
            $this->queue = [];
            $failure     = null;
            foreach ($queue as [$event, $name]) {
                try {
                    $this->dispatcher->dispatch($event, $name);
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                    if (!$rethrow) {
                        $this->logger->error('TaskBoard: a listener failed after the write was committed', ['bundle' => 'nowo_task_board', 'event' => $name ?? $event::class, 'exception' => $exception]);
                    }
                }
            }
            if ($rethrow && $failure instanceof Throwable) {
                throw $failure;
            }
        }
    }

    /** Since 2.10.0: the transaction of {@see self::begin()} rolled back: the outermost one drops what it held. */
    public function rollBack(): void
    {
        if ($this->depth === 0) {
            return;
        }
        // @igor-ignore - Scoped to the caller's transaction (see begin()).
        --$this->depth;
        if ($this->depth === 0) {
            // @igor-ignore - Scoped to the caller's transaction (see begin()).
            $this->queue = [];
        }
    }

    public function reset(): void
    {
        // @igor-ignore - Cleared between the requests of a worker.
        $this->depth = 0;
        // @igor-ignore - Cleared between the requests of a worker.
        $this->queue = [];
    }
}
