<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskApiBulkWriteEvent;
use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Since 2.2.0 (spec 213): what every write through the API owes before and after it. Before: someone must be listening to
 * `API_WRITE` (503 `AUDIT_001`), and a create must find the host's plan limits (403 `PLAN_002`). After: one `API_WRITE` and
 * the UI's log line. Nothing here writes.
 */
final readonly class TaskApiAudit
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private TaskBoardQuota $quota,
        private bool $quotaListenerRequired = true,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws TaskApiRefusal
     */
    public function requireListener(): void
    {
        if (!$this->dispatcher->hasListeners(TaskBoardEvents::API_WRITE)) {
            $this->logger->error('TaskBoard API: no listener audits API_WRITE; writes are refused.', ['bundle' => 'nowo_task_board']);

            throw new TaskApiRefusal(503, 'AUDIT_001', 'Writes are not available');
        }
    }

    /**
     * The host's plan limit on `$count` new tasks of the board, as the UI asks it.
     *
     * @throws TaskApiRefusal
     */
    public function requireTaskQuota(TaskBoard $board, int $count, object $actor): void
    {
        $this->requireQuota(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, $board->getTenantKey(), $board, $actor, $count);
    }

    /**
     * The host's plan limit on one more board of the tenant, as the UI's New board asks it.
     *
     * @throws TaskApiRefusal
     */
    public function requireQuota(string $action, ?string $tenantKey, ?TaskBoard $board, object $actor, int $count = 1): void
    {
        if ($this->quotaListenerRequired && !$this->dispatcher->hasListeners(TaskBoardEvents::QUOTA_CHECK)) {
            throw new TaskApiRefusal(403, 'PLAN_002', 'No plan limit answers for this board');
        }
        $refusal = $this->quota->refusal($action, $tenantKey, $board, $count, $actor);
        if ($refusal !== null) {
            throw new TaskApiRefusal(403, 'PLAN_002', $refusal);
        }
    }

    /**
     * Since 2.16.0 (spec 234): after an atomic bulk update's commit, its {@see TaskApiBulkWriteEvent} (the count of tasks).
     *
     * @param list<string> $taskIds
     */
    public function bulkWritten(TaskApiPrincipal $principal, ?string $tenantKey, array $taskIds): void
    {
        try {
            $this->dispatcher->dispatch(new TaskApiBulkWriteEvent($principal, $tenantKey, $taskIds), TaskBoardEvents::API_BULK_WRITE);
        } catch (Throwable $failure) {
            $this->logger->error('TaskBoard API: the API_BULK_WRITE listener failed.', ['bundle' => 'nowo_task_board', 'exception_class' => $failure::class]);
        }
    }

    /**
     * @param array<string, string> $targetIds
     * @param list<string> $changedFields
     */
    public function written(TaskApiPrincipal $principal, string $operation, ?TaskBoard $board, ?string $taskId, array $targetIds = [], array $changedFields = [], ?string $tenantKey = null): void
    {
        $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => 'api_' . $operation, 'board_id' => $board?->getId(), 'task_id' => $taskId]);
        try {
            $this->dispatcher->dispatch(new TaskApiWriteEvent($principal, $operation, $board instanceof TaskBoard ? $board->getTenantKey() : $tenantKey, $board?->getId(), $taskId, $targetIds, $changedFields), TaskBoardEvents::API_WRITE);
        } catch (Throwable $failure) {
            // The write is saved: the client gets its answer, and the host's failure is logged by class only.
            $this->logger->error('TaskBoard API: the API_WRITE listener failed.', ['bundle' => 'nowo_task_board', 'exception_class' => $failure::class]);
        }
    }
}
