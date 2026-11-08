<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Security;

use JsonException;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiIdempotencyOutcome;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function array_is_list;
use function hash;
use function hash_file;
use function http_build_query;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function ksort;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.2.0 (spec 213, FR-015): a write sent with `Idempotency-Key` and retried within 24 hours gets its first successful
 * answer again, without running twice. Keys are scoped to the token. The same key with another request is 409 `IDEMP_002`;
 * while the first one runs, 409 `IDEMP_001`. A refusal or an error is not kept, so a retry is decided afresh. A replay goes
 * through the whole pipeline first, and answers 404 once the token no longer reaches what the write touched.
 */
final readonly class TaskApiIdempotencyListener implements EventSubscriberInterface
{
    public const HEADER = 'Idempotency-Key';

    public const TTL_SECONDS = 86400;

    private const CLAIM = '_nowo_task_board_api_idempotency';

    private const TARGET = '_nowo_task_board_api_target';

    private const WRITES = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private TaskApiIdempotencyStoreInterface $store,
        private TaskApiGuard $guard,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', -8],
            // Before the pipeline's own response listener: the rate headers are added to a replay too.
            KernelEvents::RESPONSE => ['onResponse', 8],
        ];
    }

    /**
     * What the write touched, for a replay's access check: the controller tells it once the operation ran.
     */
    public static function target(Request $request, ?TaskBoard $board): void
    {
        $request->attributes->set(self::TARGET, [$board?->getTenantKey(), $board?->getId()]);
    }

    /**
     * @throws TaskApiRefusal
     */
    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->getBoolean(TaskApiRequestListener::ROUTE_FLAG) || !in_array($request->getMethod(), self::WRITES, true) || !$request->headers->has(self::HEADER)) {
            return;
        }
        $key = (string) $request->headers->get(self::HEADER);
        if ($key === '' || strlen($key) > 255) {
            throw new TaskApiRefusal(400, 'IDEMP_003', 'Invalid Idempotency-Key');
        }
        $principal = TaskApiRequestListener::principalOf($request);
        $scope     = hash('sha256', 'nowo-task-board-api|' . $principal->tokenId);
        $keyHash   = hash('sha256', $key);

        try {
            $claim = $this->store->claim($scope, $keyHash, $this->fingerprint($request), $this->clock->now()->modify('+' . self::TTL_SECONDS . ' seconds'));
        } catch (Throwable $failure) {
            $this->logger->error('TaskBoard API: the idempotency store failed.', ['bundle' => 'nowo_task_board', 'exception_class' => $failure::class]);

            throw new TaskApiRefusal(503, 'IDEMP_004', 'Idempotency is not available');
        }

        match ($claim->outcome) {
            TaskApiIdempotencyOutcome::Claimed   => $request->attributes->set(self::CLAIM, [$scope, $keyHash]),
            TaskApiIdempotencyOutcome::InFlight  => throw new TaskApiRefusal(409, 'IDEMP_001', 'A request with this key is still running'),
            TaskApiIdempotencyOutcome::Mismatch  => throw new TaskApiRefusal(409, 'IDEMP_002', 'This key was used for another request'),
            TaskApiIdempotencyOutcome::Completed => $this->replay($event, $claim->status ?? 200, (string) $claim->body, $claim->tenantKey, $claim->boardId),
        };
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $claim   = $request->attributes->get(self::CLAIM);
        if (!$event->isMainRequest() || !is_array($claim)) {
            return;
        }
        [$scope, $keyHash] = $claim;
        $response          = $event->getResponse();
        try {
            if ($response->isSuccessful()) {
                $target                = $request->attributes->get(self::TARGET);
                [$tenantKey, $boardId] = is_array($target) ? $target : [null, null];
                $this->store->complete($scope, $keyHash, $response->getStatusCode(), (string) $response->getContent(), $tenantKey, $boardId);
            } else {
                $this->store->release($scope, $keyHash);
            }
        } catch (Throwable $failure) {
            // The write is done; a retry of the same key then answers 409 until the claim is taken over.
            $this->logger->error('TaskBoard API: the idempotency store failed to keep an answer.', ['bundle' => 'nowo_task_board', 'exception_class' => $failure::class]);
        }
    }

    /**
     * @throws TaskApiRefusal
     */
    private function replay(ControllerEvent $event, int $status, string $body, ?string $tenantKey, ?string $boardId): void
    {
        if (!$this->guard->mayReplay(TaskApiRequestListener::principalOf($event->getRequest()), $tenantKey, $boardId)) {
            throw TaskApiRefusal::notFound();
        }
        $event->setController(static fn (): Response => new Response($body, $status, ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true']));
    }

    /** Method, path, query and the body with its keys sorted, so that the same JSON written another way is the same request. */
    private function fingerprint(Request $request): string
    {
        $content = (string) $request->getContent();
        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            $content = (string) json_encode($this->sorted($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            // Not JSON: compared as sent.
        }

        // An upload is part of the request: its bytes, its name and its type (audit IA-L7) count too.
        foreach ($this->files($request->files->all()) as $name => $file) {
            $content .= "\n" . $name . ':' . (string) hash_file('sha256', $file->getPathname()) . ':' . hash('sha256', $file->getClientOriginalName() . "\0" . (string) $file->getClientMimeType());
        }
        // Audit IA-L5: the query too, sorted, so `?depends_on=B` and `?dependency_of=C` are two requests.
        $query = $this->sorted($request->query->all());

        return hash('sha256', $request->getMethod() . "\n" . $request->getPathInfo() . "\n" . http_build_query(is_array($query) ? $query : []) . "\n" . $content);
    }

    /**
     * @param array<array-key, mixed> $files
     *
     * @return array<string, UploadedFile>
     */
    private function files(array $files, string $prefix = ''): array
    {
        $flat = [];
        foreach ($files as $name => $file) {
            if ($file instanceof UploadedFile) {
                $flat[$prefix . $name] = $file;
            } elseif (is_array($file)) {
                $flat += $this->files($file, $prefix . $name . '.');
            }
        }

        return $flat;
    }

    private function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sorted($item);
        }

        return $value;
    }
}
