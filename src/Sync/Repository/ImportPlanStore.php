<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function hash;
use function in_array;
use function preg_match;

use const DATE_ATOM;

/**
 * Since 3.2.0 (CU-2): the queries of import plans — their items, the run claim (one worker per plan, REQ-RUNTIME-003),
 * the workspace lock key and the retention prune. Stateless: every call goes to the database.
 */
final readonly class ImportPlanStore
{
    /** The states a slice may claim. */
    private const WAITING = ['queued', 'paused', 'rate_limited'];

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
     * older than `$staleBefore` (a worker that died). A compare-and-set: of two workers (a redelivery) one wins. Returns
     * the slice's token (every later write of the slice names it, audit ZBY-L2) or null; the plan row is read again.
     */
    public function claim(TaskSyncImportPlan $plan, DateTimeImmutable $now, DateTimeImmutable $staleBefore): ?string
    {
        $token   = Uuid::generate()->toString();
        $updated = $this->entityManager->createQuery(
            'UPDATE ' . TaskSyncImportPlan::class . ' p SET p.status = :running, p.heartbeatAt = :now, p.runToken = :token
             WHERE p.id = :id AND (p.status IN (:waiting) OR (p.status = :running AND (p.heartbeatAt IS NULL OR p.heartbeatAt < :stale)))',
        )
            ->setParameter('running', ImportPlanStatus::Running->value)
            ->setParameter('waiting', self::WAITING)
            ->setParameter('now', $now, 'datetime_immutable')
            ->setParameter('stale', $staleBefore, 'datetime_immutable')
            ->setParameter('token', $token)
            ->setParameter('id', $plan->getId())
            ->execute();
        $this->entityManager->refresh($plan);

        return (int) $updated === 1 ? $token : null;
    }

    /** The heartbeat of the slice holding `$token`; false when another slice took the plan over (or it ended). */
    public function heartbeat(string $planId, string $token, DateTimeImmutable $now): bool
    {
        return $this->fenced('p.heartbeatAt = :now', $planId, $token, ['now' => [$now, 'datetime_immutable']], true);
    }

    /**
     * @param array<string, int|list<string>|string> $report
     */
    public function saveReport(string $planId, string $token, array $report): bool
    {
        return $this->fenced('p.report = :report', $planId, $token, ['report' => [$report, 'json']], true);
    }

    /**
     * Ends the slice in a resumable state (`paused` or `rate_limited`), due at `$resumeAt` (null: until *Resume*).
     * Refused (false) once a cancel was asked: the slice then ends the plan as cancelled (audit ZBYv-L2).
     *
     * @param array<string, int|list<string>|string> $report
     */
    public function hold(string $planId, string $token, ImportPlanStatus $status, ?DateTimeImmutable $resumeAt, ?string $errorCode, array $report): bool
    {
        return $this->fenced(
            'p.status = :status, p.resumeAt = :resume, p.errorCode = :code, p.report = :report, p.runToken = NULL, p.heartbeatAt = NULL',
            $planId,
            $token,
            ['status' => [$status->value, null], 'resume' => [$resumeAt, 'datetime_immutable'], 'code' => [$errorCode, null], 'report' => [$report, 'json'], 'asked' => [false, 'boolean']],
            false,
            ' AND p.cancelRequested = :asked',
        );
    }

    /**
     * A final state of the slice holding `$token`: the workspace lock is released only by it.
     *
     * @param array<string, int|list<string>|string> $report
     */
    public function finish(string $planId, string $token, ImportPlanStatus $status, DateTimeImmutable $now, ?string $errorCode, array $report): bool
    {
        return $this->fenced(
            'p.status = :status, p.finishedAt = :now, p.errorCode = :code, p.report = :report, p.lockKey = NULL, p.resumeAt = NULL, p.runToken = NULL, p.heartbeatAt = NULL',
            $planId,
            $token,
            ['status' => [$status->value, null], 'now' => [$now, 'datetime_immutable'], 'code' => [$errorCode, null], 'report' => [$report, 'json']],
        );
    }

    /**
     * Cancel (audit ZBY-L2, ZBYv-L2), one decision under the row lock: a waiting plan (queued, paused, rate-limited)
     * ends now — true; a running one is asked to stop before its next item (a pause of its slice then ends it too) —
     * false; null when it is no longer active. No slice can hold or end the plan between the read and the write.
     */
    public function cancel(string $planId, DateTimeImmutable $now): ?bool
    {
        // @igor-ignore - The closure runs at once inside transactional() and is never stored.
        return $this->entityManager->getConnection()->transactional(function () use ($planId, $now): ?bool {
            $status = $this->entityManager->createQuery('SELECT p.status FROM ' . TaskSyncImportPlan::class . ' p WHERE p.id = :id')
                ->setParameter('id', $planId)
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getSingleScalarResult();
            if (in_array($status, self::WAITING, true)) {
                return $this->update(
                    'p.status = :cancelled, p.finishedAt = :now, p.lockKey = NULL, p.resumeAt = NULL, p.runToken = NULL, p.heartbeatAt = NULL, p.cancelRequested = true',
                    'p.id = :id',
                    ['cancelled' => [ImportPlanStatus::Cancelled->value, null], 'now' => [$now, 'datetime_immutable'], 'id' => [$planId, null]],
                );
            }
            if ($status !== ImportPlanStatus::Running->value) {
                return null;
            }
            $this->update('p.cancelRequested = true', 'p.id = :id', ['id' => [$planId, null]], true);

            return false;
        });
    }

    /** *Resume*: a paused or rate-limited plan is due now (a compare-and-set on its status). */
    public function makeDue(string $planId, DateTimeImmutable $now): bool
    {
        return $this->update('p.resumeAt = :now', 'p.id = :id AND p.status IN (:held)', ['now' => [$now, 'datetime_immutable'], 'id' => [$planId, null], 'held' => [[ImportPlanStatus::Paused->value, ImportPlanStatus::RateLimited->value], null]], true);
    }

    /** The plan as the database holds it now (the conditional updates bypass the identity map). */
    public function reload(string $planId): ?TaskSyncImportPlan
    {
        $plan = $this->find($planId);
        if ($plan instanceof TaskSyncImportPlan) {
            $this->entityManager->refresh($plan);
        }

        return $plan;
    }

    /**
     * A write of the running slice holding `$token`.
     *
     * @param array<string, array{0: mixed, 1: string|null}> $parameters
     */
    private function fenced(string $set, string $planId, string $token, array $parameters, bool $mayBeUnchanged = false, string $andWhere = ''): bool
    {
        return $this->update($set, 'p.id = :id AND p.runToken = :token AND p.status = :running' . $andWhere, $parameters + ['id' => [$planId, null], 'token' => [$token, null], 'running' => [ImportPlanStatus::Running->value, null]], $mayBeUnchanged);
    }

    /**
     * `$mayBeUnchanged`: only for writes that can leave the row as it was (a heartbeat in the same second, the same
     * report, a flag already set); a write that changes the status never takes the fallback (audit ZBYv-L2).
     *
     * @param array<string, array{0: mixed, 1: string|null}> $parameters
     */
    private function update(string $set, string $where, array $parameters, bool $mayBeUnchanged = false): bool
    {
        $query = $this->entityManager->createQuery('UPDATE ' . TaskSyncImportPlan::class . ' p SET ' . $set . ' WHERE ' . $where);
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        $updated = (int) $query->execute();

        // MySQL and MariaDB count changed rows, not matched ones: a write that leaves the row as it was (the same
        // heartbeat second, the same report) reports 0 although the guard still holds — so check the guard itself.
        return $updated === 1 || ($mayBeUnchanged && $updated === 0 && $this->matches($where, $parameters));
    }

    /**
     * @param array<string, array{0: mixed, 1: string|null}> $parameters
     */
    private function matches(string $where, array $parameters): bool
    {
        $query = $this->entityManager->createQuery('SELECT COUNT(p.id) FROM ' . TaskSyncImportPlan::class . ' p WHERE ' . $where);
        foreach ($parameters as $name => [$value, $type]) {
            if (preg_match('/:' . $name . '\\b/', $where) === 1) {
                $query->setParameter($name, $value, $type);
            }
        }

        return (int) $query->getSingleScalarResult() === 1;
    }

    /**
     * Since the audit ZBY-I6: the plans a user created or started, for the host's access (export) request — ids,
     * dates and states only.
     *
     * @return list<array{planId: string, tenantKey: string|null, provider: string, workspaceId: string|null, role: string, status: string, createdAt: string, startedAt: string|null, finishedAt: string|null}>
     */
    public function exportFor(string $userId): array
    {
        /** @var list<array{id: string, tenantKey: string|null, provider: string, workspaceId: string|null, createdById: string|null, startedById: string|null, status: string, createdAt: DateTimeImmutable, startedAt: DateTimeImmutable|null, finishedAt: DateTimeImmutable|null}> $plans */
        $plans = $this->entityManager->createQuery(
            'SELECT p.id, p.tenantKey, p.provider, p.workspaceId, p.createdById, p.startedById, p.status, p.createdAt, p.startedAt, p.finishedAt
             FROM ' . TaskSyncImportPlan::class . ' p WHERE p.createdById = :id OR p.startedById = :id ORDER BY p.createdAt ASC',
        )
            ->setParameter('id', $userId)
            ->getArrayResult();
        $rows = [];
        foreach ($plans as $plan) {
            $rows[] = [
                'planId'      => $plan['id'],
                'tenantKey'   => $plan['tenantKey'],
                'provider'    => $plan['provider'],
                'workspaceId' => $plan['workspaceId'],
                'role'        => $plan['startedById'] === $userId ? ($plan['createdById'] === $userId ? 'created_started' : 'started') : 'created',
                'status'      => $plan['status'],
                'createdAt'   => $plan['createdAt']->format(DATE_ATOM),
                'startedAt'   => $plan['startedAt']?->format(DATE_ATOM),
                'finishedAt'  => $plan['finishedAt']?->format(DATE_ATOM),
            ];
        }

        return $rows;
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
