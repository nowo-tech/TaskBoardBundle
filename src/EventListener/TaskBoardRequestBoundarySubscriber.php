<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\EventListener;

use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function class_exists;
use function max;

/**
 * Since 2.11.1 (REQ-RUNTIME-002): every unit of work starts and ends with empty request memos — the avatar URLs
 * ({@see TaskMemberAvatars}), the repeat icons ({@see TaskRecurrenceView}),
 * the synced people ({@see SyncPeopleViewProvider}), the change cause
 * ({@see TaskChangeContext}) and the held task events
 * ({@see TaskEventDeferral}).
 *
 * Boundaries:
 * - main `kernel.request` (priority 4096, before any bundle listener) and `kernel.terminate` (priority -4096, after
 *   the host's terminate listeners); sub-requests (fragments, ESI) share the main request's memos and are left alone;
 * - Messenger `WorkerMessageReceivedEvent` (priority 4096) and `WorkerMessageHandledEvent` /
 *   `WorkerMessageFailedEvent` (priority -4096);
 * - `console.command` and `console.terminate` of the OUTERMOST command only, and only outside a Messenger message and
 *   outside a main request: a command run in-process from another command, from a message handler or from a
 *   controller (`Application::doRun()`) must not clear the state of the unit that runs it (an open
 *   {@see TaskEventDeferral::defer()} would lose its held events). The depth is counted
 *   on `console.command` / `console.terminate`; the message flag is set on received and unset on handled / failed /
 *   worker stopped; the request flag is set on main `kernel.request` and unset on main `kernel.finish_request`.
 *
 * The same services keep {@see ResetInterface} (`kernel.reset`); this subscriber keeps them correct where the services
 * resetter does not run: a host that removes or bypasses `services_resetter` (or drives `http_kernel` directly),
 * Messenger consumers started with `--no-reset`, and long-running console commands.
 *
 * The services arrive as a lazy iterator of `IGNORE_ON_UNINITIALIZED_REFERENCE` references: a boundary only clears
 * instances that already exist and never builds one.
 *
 * @internal registered by the bundle; the constructor is not a public API
 */
final class TaskBoardRequestBoundarySubscriber implements EventSubscriberInterface
{
    /**
     * Console commands running in this process (nested in-process runs count). Process-lifetime counter bounded by
     * the nesting depth; intentionally not reset by `kernel.reset` (a reset between the messages of a
     * `messenger:consume` command would lose the outer command).
     */
    private int $consoleDepth = 0;

    /** A Messenger message is being handled (between received and handled / failed). */
    private bool $messageInFlight = false;

    /** A main request is being handled (between its `kernel.request` and its `kernel.finish_request`). */
    private bool $requestInFlight = false;

    /**
     * @param iterable<ResetInterface> $state the request memos of the bundle that exist in the container
     */
    public function __construct(private readonly iterable $state)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST        => ['onKernelRequest', 4096],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', -4096],
            KernelEvents::TERMINATE      => ['clear', -4096],
        ];

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $events[WorkerMessageReceivedEvent::class] = ['onMessageReceived', 4096];
            $events[WorkerMessageHandledEvent::class]  = ['onMessageFinished', -4096];
            $events[WorkerMessageFailedEvent::class]   = ['onMessageFinished', -4096];
            // A listener may skip a received message (no handled / failed event follows).
            $events[WorkerStoppedEvent::class] = ['onWorkerStopped', -4096];
        }

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND]   = ['onConsoleCommand', 4096];
            $events[ConsoleEvents::TERMINATE] = ['onConsoleTerminate', -4096];
        }

        return $events;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
        $this->requestInFlight = true;
        $this->clear();
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // @igor-ignore - Unit-of-work flag, set and unset at the main request boundaries.
            $this->requestInFlight = false;
        }
    }

    /** Start of a Messenger message: nothing from the previous message or the worker's idle time is kept. */
    public function onMessageReceived(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        $this->clear();
    }

    /** End of a message (handled or failed). */
    public function onMessageFinished(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clear();
    }

    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    /** Start of a command: clears only when it is the outermost one, outside a message and a main request. */
    public function onConsoleCommand(): void
    {
        if ($this->isOutermostUnit()) {
            $this->clear();
        }
        // @igor-ignore - Nesting counter of the running commands (no request data).
        ++$this->consoleDepth;
    }

    /** End of a command: clears only when the outermost one ends, outside a message and a main request. */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->isOutermostUnit()) {
            $this->clear();
        }
    }

    /** Clears every request memo of the bundle that exists. */
    public function clear(): void
    {
        foreach ($this->state as $state) {
            $state->reset();
        }
    }

    private function isOutermostUnit(): bool
    {
        return $this->consoleDepth === 0 && !$this->messageInFlight && !$this->requestInFlight;
    }
}
