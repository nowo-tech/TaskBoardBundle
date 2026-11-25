<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function hash;

/**
 * Since 3.2.0 (CU-2): the queries of import plans — their items, the run claim (one worker per plan, REQ-RUNTIME-003),
 * the workspace lock key and the retention prune. Stateless: every call goes to the database.
 */
final readonly class ImportPlanStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $id): ?TaskSyncImportPlan
    {
        return $this->entityManager->find(TaskSyncImportPlan::class, $id);
    }

    /**
     * The plans of a tenant, newest first (the tenant key compared exactly).
     *
     * @return list<TaskSyncImportPlan>
     */
    public function forTenant(?string $tenantKey, int $limit): array
    {
        /** @var list<TaskSyncImportPlan> $plans */
        $plans = $this->entityManager->getRepository(TaskSyncImportPlan::class)->findBy(['tenantKey' => $tenantKey], ['createdAt' => 'DESC'], $limit);

        return array_values(array_filter($plans, static fn (TaskSyncImportPlan $plan): bool => $plan->getTenantKey() === $tenantKey));
    }

    /**
     * @return list<TaskSyncImportPlanItem>
     */
    public function items(TaskSyncImportPlan $plan): array
    {
        /** @var list<TaskSyncImportPlanItem> $items */
        $items = $this->entityManager->getRepository(TaskSyncImportPlanItem::class)->findBy(['plan' => $plan], ['position' => 'ASC']);

        return $items;
    }

    public function removeItems(TaskSyncImportPlan $plan): void
    {
        $this->entityManager->createQuery('DELETE FROM ' . TaskSyncImportPlanItem::class . ' i WHERE i.plan = :plan')
            ->setParameter('plan', $plan->getId())
            ->execute();
    }

    /** The lock key of a tenant, provider and workspace (an active plan holds it; the unique key refuses a second). */
    public static function lockKey(?string $tenantKey, string $provider, string $workspaceId): string
    {
        return hash('sha256', ($tenantKey ?? '') . "\x1F" . $provider . "\x1F" . $workspaceId);
    }

    /** The active plan that holds the workspace of the tenant, if any. */
    public function activeFor(?string $tenantKey, string $provider, string $workspaceId): ?TaskSyncImportPlan
    {
        return $this->entityManager->getRepository(TaskSyncImportPlan::class)->findOneBy(['lockKey' => self::lockKey($tenantKey, $provider, $workspaceId)]);
    }

    /** Whether another plan of the tenant (started at least once) imported the same workspace (Q-CU-12 warning). */
    public function workspaceImportedBefore(TaskSyncImportPlan $plan): bool
    {
        $ids = $this->entityManager->createQuery(
            'SELECT p.id, p.tenantKey FROM ' . TaskSyncImportPlan::class . ' p
             WHERE p.provider = :provider AND p.workspaceId = :workspace AND p.id <> :id AND p.startedAt IS NOT NULL',
        )
            ->setParameter('provider', $plan->getProvider())
            ->setParameter('workspace', (string) $plan->getWorkspaceId())
            ->setParameter('id', $plan->getId())
            ->getArrayResult();

        return array_filter(array_map(static fn (array $row): bool => $row['tenantKey'] === $plan->getTenantKey(), $ids)) !== [];
    }

    /**
     * Takes the run of a plan for one slice: a queued, paused or rate-limited plan, or a running one whose heartbeat is
     * older than `$staleBefore` (a worker that died). A compare-and-set: of two workers (a redelivery) one wins. The plan
     * row is read again afterwards.
     */
    public function claim(TaskSyncImportPlan $plan, DateTimeImmutable $now, DateTimeImmutable $staleBefore): bool
    {
        $updated = $this->entityManager->createQuery(
            'UPDATE ' . TaskSyncImportPlan::class . ' p SET p.status = :running, p.heartbeatAt = :now
             WHERE p.id = :id AND (p.status IN (:waiting) OR (p.status = :running AND (p.heartbeatAt IS NULL OR p.heartbeatAt < :stale)))',
        )
            ->setParameter('running', ImportPlanStatus::Running->value)
            ->setParameter('waiting', [ImportPlanStatus::Queued->value, ImportPlanStatus::Paused->value, ImportPlanStatus::RateLimited->value])
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('stale', $staleBefore, 'datetime_immutable')
            ->setParameter('id', $plan->getId())
            ->execute();
        $this->entityManager->refresh($plan);

        return (int) $updated === 1;
    }

    public function heartbeat(TaskSyncImportPlan $plan, DateTimeImmutable $now): void
    {
        $this->entityManager->createQuery('UPDATE ' . TaskSyncImportPlan::class . ' p SET p.heartbeatAt = :now WHERE p.id = :id AND p.status = :running')
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('id', $plan->getId())
            ->setParameter('running', ImportPlanStatus::Running->value)
            ->execute();
    }

    /**
     * Retention (Q-CU-13): deletes the plans that ended before `$before` and the drafts created before it, with their
     * items. The imported boards and tasks stay. Returns the number of plans deleted.
     */
    public function prune(DateTimeImmutable $before): int
    {
        $ids = $this->entityManager->createQuery(
            'SELECT p.id FROM ' . TaskSyncImportPlan::class . ' p
             WHERE (p.finishedAt IS NOT NULL AND p.finishedAt < :before AND p.lockKey IS NULL)
                OR (p.status = :draft AND p.createdAt < :before)',
        )
            ->setParameter('before', $before, 'datetime_immutable')
            ->setParameter('draft', ImportPlanStatus::Draft->value)
            ->setMaxResults(500)
            ->getSingleColumnResult();
        if ($ids === []) {
            return 0;
        }
        $this->entityManager->createQuery('DELETE FROM ' . TaskSyncImportPlanItem::class . ' i WHERE i.plan IN (:ids)')->setParameter('ids', $ids)->execute();
        $this->entityManager->createQuery('DELETE FROM ' . TaskSyncImportPlan::class . ' p WHERE p.id IN (:ids)')->setParameter('ids', $ids)->execute();

        return count($ids);
    }

    /** Deletes a plan and its items (never its boards or tasks). */
    public function delete(TaskSyncImportPlan $plan): void
    {
        $this->removeItems($plan);
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
    }

    /** Since 3.2.0: an erased user no longer appears on the plans they created or started. */
    public function forgetUser(string $userId): void
    {
        $this->entityManager->createQuery('UPDATE ' . TaskSyncImportPlan::class . ' p SET p.createdById = NULL WHERE p.createdById = :id')->setParameter('id', $userId)->execute();
        $this->entityManager->createQuery('UPDATE ' . TaskSyncImportPlan::class . ' p SET p.startedById = NULL WHERE p.startedById = :id')->setParameter('id', $userId)->execute();
    }
}
