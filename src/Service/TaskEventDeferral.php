<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

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

    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
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
     * Runs `$write` holding the events it dispatches; dispatches them after it returns, or drops them if it throws.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function defer(callable $write): mixed
    {
        // @igor-ignore - Scoped to the call (see above).
        ++$this->depth;
        try {
            $result = $write();
        } catch (Throwable $failure) {
            // @igor-ignore - Scoped to the call (see above).
            --$this->depth;
            if ($this->depth === 0) {
                // @igor-ignore - Scoped to the call (see above).
                $this->queue = [];
            }

            throw $failure;
        }
        // @igor-ignore - Scoped to the call (see above).
        --$this->depth;
        if ($this->depth === 0) {
            $queue = $this->queue;
            // @igor-ignore - Scoped to the call (see above).
            $this->queue = [];
            foreach ($queue as [$event, $name]) {
                $this->dispatcher->dispatch($event, $name);
            }
        }

        return $result;
    }

    public function reset(): void
    {
        // @igor-ignore - Cleared between the requests of a worker.
        $this->depth = 0;
        // @igor-ignore - Cleared between the requests of a worker.
        $this->queue = [];
    }
}
