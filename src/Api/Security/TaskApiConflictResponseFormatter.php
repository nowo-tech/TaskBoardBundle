<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Since 2.22.0 (owner decision, transaction-doctrine 1.3): shapes the lock-conflict answer of nowo-tech/transaction-doctrine's
 * responder for the Task API's own requests, in the API's error shape: `{"err", "ECODE": "CONFLICT_001"}` (retry after
 * `Retry-After`), or `CONFLICT_002` when the write unit already committed part of its work (no `Retry-After`: read the task
 * again first). The requests are recognised by the route flag the API's routes carry ({@see TaskApiRequestListener::ROUTE_FLAG},
 * every route name and tenant copy included), so a host needs no route-name matching; the status, `Retry-After` and
 * `Cache-Control` stay the responder's. Every other request falls through (`null`).
 *
 * Registered (tag `nowo_transaction_doctrine.conflict_response_formatter`) only when the interface exists and `api.enabled`.
 * Stateless.
 */
final readonly class TaskApiConflictResponseFormatter implements ConflictResponseFormatterInterface
{
    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        if (!$request->attributes->getBoolean(TaskApiRequestListener::ROUTE_FLAG)) {
            return null;
        }

        return new JsonResponse($context->committed
            ? ['err' => TaskApiRefusal::CONFLICT_PARTIAL_MESSAGE, 'ECODE' => TaskApiRefusal::CONFLICT_PARTIAL]
            : ['err' => TaskApiRefusal::CONFLICT_MESSAGE, 'ECODE' => TaskApiRefusal::CONFLICT], $context->status);
    }
}
