<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_int;

/**
 * Since 2.22.0 (audit SU-L1): whether a Task API request has already committed part of its work when it fails on a lock
 * conflict, so the answer is `CONFLICT_002` without `Retry-After` (read again) instead of `CONFLICT_001` (send the same
 * request): a post-commit `TASK_*` listener, a write that commits in steps, a host decorator.
 *
 * nowo-tech/transaction-doctrine's {@see CommitLedger} counts every durable write of the process (each COMMIT, each write
 * statement outside a transaction) through its DBAL middleware, which the bundle registers on the connections
 * (`doctrine.middleware`). When the controller of an API route is chosen — after the idempotency listener claimed its key
 * (priority -8) — the count is kept in the request's attributes; a later count above it means this request committed.
 * Nothing is kept in the service: the mark lives and dies with the request (FrankenPHP worker mode, kernel not reset), and
 * a sub-request or another request never reads it.
 */
final readonly class TaskApiCommitWatermark implements EventSubscriberInterface
{
    /** Request attribute holding the ledger's count when the controller was chosen. */
    public const ATTRIBUTE = '_nowo_task_board_api_commits';

    public function __construct(private CommitLedger $ledger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', -16]];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->attributes->getBoolean(TaskApiRequestListener::ROUTE_FLAG)) {
            $request->attributes->set(self::ATTRIBUTE, $this->ledger->count());
        }
    }

    /** Whether the request committed a write since its controller was chosen; false before that point or off the API. */
    public function committedSince(Request $request): bool
    {
        $mark = $request->attributes->get(self::ATTRIBUTE);

        return is_int($mark) && $this->ledger->count() > $mark;
    }
}
