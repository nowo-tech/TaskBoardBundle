<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use Closure;
use JsonException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\Security\TaskApiIdempotencyListener;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Nowo\TaskBoardBundle\Doctrine\LockConflictChain;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function array_is_list;
use function hash;
use function is_array;
use function json_decode;
use function json_encode;
use function ksort;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 3.4.0 (client API v1, §4.1 / §8): `Idempotency-Key` on the client API's creates (required) and other writes
 * (optional), with the Task API's store (`api.hooks.idempotency_store`, default the bundle's table): 24 hours, scoped to
 * the token exactly as the Task API scopes it (`sha256('nowo-task-board-api|' . tokenId)`), so the host's
 * `TaskApiIdempotencyStoreInterface::forget()` on revocation or erasure drops the client API's answers too. The same key
 * with another request (method, path, query, context, the JSON body with sorted keys) is `409 idempotency_mismatch`;
 * while the first runs, `409 idempotency_in_flight`; the store failing is `503 idempotency_unavailable`. A refusal or an
 * error is not kept (a retry is decided afresh), except a lock conflict after part of the write committed (`409
 * partial`: the same key must not write again). A replay re-checks reach first: once the token no longer reaches the
 * board the write touched, it is `404 not_found`. Stateless.
 *
 * @internal
 */
final readonly class TaskClientIdempotency
{
    public function __construct(
        private TaskApiIdempotencyStoreInterface $store,
        private TaskApiGuard $guard,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Runs `$write` once per key. `$write` returns the response and the board it touched (for a replay's reach check).
     *
     * @param Closure(): array{0: Response, 1: TaskBoard|null} $write
     *
     * @throws TaskClientApiException
     */
    public function run(Request $request, TaskClientCall $call, bool $required, Closure $write): Response
    {
        $key = $request->headers->get(TaskApiIdempotencyListener::HEADER);
        if ($key === null) {
            if ($required) {
                throw TaskClientApiException::invalidField(TaskApiIdempotencyListener::HEADER);
            }

            return $write()[0];
        }
        if ($key === '' || strlen($key) > 255) {
            throw TaskClientApiException::invalidField(TaskApiIdempotencyListener::HEADER);
        }
        $scope   = self::scope($call->client->tokenId);
        $keyHash = hash('sha256', $key);
        try {
            $claim = $this->store->claim($scope, $keyHash, $this->fingerprint($request, $call), $this->clock->now()->modify('+' . TaskApiIdempotencyListener::TTL_SECONDS . ' seconds'));
        } catch (Throwable $failure) {
            $this->logger->error('TaskBoard client API: the idempotency store failed.', ['bundle' => 'nowo_task_board', 'exception_class' => $failure::class]);

            throw new TaskClientApiException('idempotency_unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($claim->outcome === TaskApiIdempotencyOutcome::InFlight) {
            throw new TaskClientApiException('idempotency_in_flight', Response::HTTP_CONFLICT);
        }
        if ($claim->outcome === TaskApiIdempotencyOutcome::Mismatch) {
            throw new TaskClientApiException('idempotency_mismatch', Response::HTTP_CONFLICT);
        }
        if ($claim->outcome === TaskApiIdempotencyOutcome::Completed) {
            if (!$this->guard->mayReplay($call->principal, $claim->tenantKey, $claim->boardId)) {
                throw TaskClientApiException::notFound();
            }

            return new Response((string) $claim->body, $claim->status ?? 200, ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true']);
        }

        try {
            [$response, $board] = $write();
        } catch (Throwable $failure) {
            $this->settle($scope, $keyHash, null, null, LockConflictChain::isConflict($failure) && LockConflictChain::committed($failure));

            throw $failure;
        }
        $this->settle($scope, $keyHash, $response, $board, false);

        return $response;
    }

    /** The store's scope of a token: the Task API's, so one `forget()` drops both APIs' answers. */
    public static function scope(string $tokenId): string
    {
        return hash('sha256', 'nowo-task-board-api|' . $tokenId);
    }

    private function settle(string $scope, string $keyHash, ?Response $response, ?TaskBoard $board, bool $partial): void
    {
        try {
            if ($response instanceof Response && $response->isSuccessful()) {
                $this->store->complete($scope, $keyHash, $response->getStatusCode(), (string) $response->getContent(), $board?->getTenantKey(), $board?->getId());
            } elseif ($partial) {
                $this->store->complete($scope, $keyHash, Response::HTTP_CONFLICT, (string) json_encode(['ok' => false, 'error' => 'partial', 'code' => 'CONFLICT_002']), $board?->getTenantKey(), $board?->getId());
            } else {
                $this->store->release($scope, $keyHash);
            }
        } catch (Throwable $failure) {
            // The write is done (or refused); a retry of the same key then answers 409 until the claim is taken over.
            $this->logger->error('TaskBoard client API: the idempotency store failed to keep an answer.', ['bundle' => 'nowo_task_board', 'exception_class' => $failure::class]);
        }
    }

    /** Method, path, query, the context, `If-Match` and the body with its keys sorted. */
    private function fingerprint(Request $request, TaskClientCall $call): string
    {
        $content = (string) $request->getContent();
        try {
            $content = (string) json_encode(self::sorted(json_decode($content, true, 64, JSON_THROW_ON_ERROR)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            // Not JSON: compared as sent.
        }
        $query = self::sorted($request->query->all());

        // Audit ZCS-I3: the revision a change was sent against is part of the request (a fresh If-Match needs a fresh key).
        $ifMatch = trim((string) $request->headers->get('If-Match', ''));

        return hash('sha256', 'client/v1' . "\n" . $request->getMethod() . "\n" . $request->getPathInfo() . "\n" . json_encode($query) . "\n" . $call->contextKey . "\n" . $ifMatch . "\n" . $content);
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sorted($item);
        }

        return $value;
    }
}
