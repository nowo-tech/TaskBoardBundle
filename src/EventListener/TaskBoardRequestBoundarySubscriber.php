<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\EventListener;

use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;
use Nowo\TaskBoardBundle\Service\TaskEventDeferral;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Contracts\Service\ResetInterface;

use function class_exists;

use const PHP_INT_MAX;

/**
 * Since 2.11.1 (REQ-RUNTIME-002): every unit of work starts and ends with empty request memos — the avatar URLs
 * ({@see TaskMemberAvatars}), the repeat icons ({@see TaskRecurrenceView}),
 * the synced people ({@see SyncPeopleViewProvider}), the change cause
 * ({@see TaskChangeContext}) and the held task events
 * ({@see TaskEventDeferral}).
 *
 * Boundaries (every unit-of-work state change at PHP_INT_MAX, through {@see UnitOfWorkFrames}, 2.14.1 audit NM / NL2-v):
 * - main `kernel.request` (PHP_INT_MAX, before any bundle listener) and `kernel.terminate` (priority -4096, after the
 *   host's terminate listeners), only for the outermost unit: not inside a Messenger message, a console command or
 *   another main request. A main request handled in-process by a message handler, a command or a controller
 *   (`http_kernel->handle()`, e.g. to render a PDF or an e-mail) belongs to the outer unit and must not drop its open
 *   {@see TaskEventDeferral::defer()}; sub-requests (fragments, ESI) share the main request's memos and are left alone;
 * - Messenger `WorkerMessageReceivedEvent` (PHP_INT_MAX) and `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`
 *   (priority -4096). `WorkerMessageSkipEvent` (dispatched by `messenger:failed:retry` only) and `WorkerStoppedEvent`
 *   end the in-flight message without clearing;
 * - `console.command` (PHP_INT_MAX) and `console.terminate` (priority -4096) of the OUTERMOST run only, outside a
 *   Messenger message and a main request: a command run in-process from another command, from a message handler or
 *   from a controller (`Application::doRun()`) must not clear the state of the unit that runs it (an open
 *   {@see TaskEventDeferral::defer()} would lose its held events). Each run is a frame keyed by its command and its
 *   input, removed by its own `console.terminate`; a run whose `console.error` listener threw (no terminate follows)
 *   is dropped at the next request, message, command or worker stop. Before 2.14.1 a plain depth counter could stay
 *   above zero for good after a throwing or propagation-stopping console listener, and the memos were then never
 *   cleared between requests again.
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
final readonly class TaskBoardRequestBoundarySubscriber implements EventSubscriberInterface
{
    /**
     * The units of work running in this process (process lifetime, bounded by the nesting depth); intentionally not
     * reset by `kernel.reset` (a reset between the messages of a `messenger:consume` command would lose the command).
     */
    private UnitOfWorkFrames $units;

    /**
     * @param iterable<ResetInterface> $state the request memos of the bundle that exist in the container
     */
    public function __construct(private iterable $state)
    {
        $this->units = new UnitOfWorkFrames();
    }

    /**
     * @return array<string, array{0: string, 1: int}|list<array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST        => ['onKernelRequest', PHP_INT_MAX],
            KernelEvents::FINISH_REQUEST => ['onKernelFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE      => [['onKernelTerminateStarted', PHP_INT_MAX], ['onKernelTerminate', -4096]],
        ];

        if (class_exists(WorkerMessageReceivedEvent::class)) {
            $events[WorkerMessageReceivedEvent::class] = ['onMessageReceived', PHP_INT_MAX];
            $events[WorkerMessageHandledEvent::class]  = [['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -4096]];
            $events[WorkerMessageFailedEvent::class]   = [['onMessageEnded', PHP_INT_MAX], ['onMessageFinished', -4096]];
            // Dispatched by messenger:failed:retry only, when a message is skipped.
            $events[WorkerMessageSkipEvent::class] = ['onMessageEnded', PHP_INT_MAX];
            $events[WorkerStoppedEvent::class]     = ['onWorkerStopped', PHP_INT_MAX];
        }

        if (class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::COMMAND]   = ['onConsoleCommand', PHP_INT_MAX];
            $events[ConsoleEvents::ERROR]     = ['onConsoleError', PHP_INT_MAX];
            $events[ConsoleEvents::TERMINATE] = [['onConsoleCommandEnded', PHP_INT_MAX], ['onConsoleTerminate', -4096]];
        }

        return $events;
    }

    /** Start of a main request: clears only for the outermost unit. */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->units->requestStarted()) {
            $this->clear();
        }
    }

    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestFinished();
        }
    }

    /**
     * `kernel.terminate` (PHP_INT_MAX): a top-level request boundary resets the main-request counter, even when the
     * request's `kernel.finish_request` never came (audit NR-b M1).
     */
    public function onKernelTerminateStarted(): void
    {
        $this->units->requestBoundaryEnded();
    }

    /**
     * End of a main request: clears only when nothing else runs (an in-process `http_kernel->handle()` run by a message
     * handler, a command or a controller ends inside the outer unit).
     */
    public function onKernelTerminate(): void
    {
        if ($this->units->isIdle()) {
            $this->clear();
        }
    }

    /** Start of a Messenger message: nothing from the previous message or the worker's idle time is kept. */
    public function onMessageReceived(): void
    {
        $this->units->messageReceived();
        $this->clear();
    }

    /** A message was handled, failed or skipped (PHP_INT_MAX): no message is in flight any more. */
    public function onMessageEnded(): void
    {
        $this->units->messageFinished();
    }

    /** End of a message (handled or failed, late). */
    public function onMessageFinished(): void
    {
        $this->clear();
    }

    public function onWorkerStopped(): void
    {
        $this->units->workerStopped();
    }

    /** Start of a command run: clears only for the outermost one, outside a message and a main request. */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->units->commandStarted($event)) {
            $this->clear();
        }
    }

    /** A command run failed: its frame is marked (dropped at the next unit when no terminate follows). */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->units->commandFailed($event);
    }

    /** End of a command run (PHP_INT_MAX, before any listener that could throw or stop the propagation). */
    public function onConsoleCommandEnded(ConsoleTerminateEvent $event): void
    {
        $this->units->commandEnded($event);
    }

    /** End of a command run (late): clears only when that run started as the outermost unit. */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
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
}
