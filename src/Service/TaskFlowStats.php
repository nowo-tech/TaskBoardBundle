<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\View\TaskColumnEntry;
use Nowo\TaskBoardBundle\View\TaskCompletionSample;
use Nowo\TaskBoardBundle\View\TaskFlowDay;

use function array_key_last;
use function array_slice;
use function count;
use function is_scalar;
use function is_string;
use function ksort;
use function sprintf;
use function usort;

use const SORT_STRING;

/**
 * Since 2.29.0: the bundle's {@see TaskFlowStatsInterface}. Reads only; stateless (FrankenPHP worker safe). The daily
 * flow scans the window's rows in keyset batches (bounded memory) and counts the days in PHP, so the day boundary
 * follows the asked time zone on every database.
 */
final readonly class TaskFlowStats implements TaskFlowStatsInterface
{
    private const BATCH = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        // Rows per batch of the daily scan (a test sets a small one to cross a batch).
        private int $batchSize = self::BATCH,
    ) {
    }

    public function dailyFlow(array $tenantKeys, DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeZone $timeZone = null): array
    {
        $keys = $this->keys($tenantKeys);
        $this->window($from, $to);
        if ($keys === []) {
            return [];
        }
        $zone = $timeZone ?? new DateTimeZone('UTC');
        /** @var array<string, array<string, array{0: int, 1: int}>> $days */
        $days = [];
        foreach (['createdAt' => 0, 'completedAt' => 1] as $field => $slot) {
            foreach ($this->scan($keys, $field, $from, $to) as [$tenant, $at]) {
                $day = $at->setTimezone($zone)->format('Y-m-d');
                $days[$tenant][$day] ??= [0, 0];
                ++$days[$tenant][$day][$slot];
            }
        }
        ksort($days, SORT_STRING);
        $flow = [];
        foreach ($days as $tenant => $perDay) {
            ksort($perDay, SORT_STRING);
            foreach ($perDay as $day => [$created, $completed]) {
                $flow[] = new TaskFlowDay((string) $tenant, (string) $day, $created, $completed);
            }
        }

        return $flow;
    }

    public function completedSamples(array $tenantKeys, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array
    {
        $keys = $this->keys($tenantKeys);
        $this->window($from, $to);
        $this->limit($limit);
        if ($keys === []) {
            return [];
        }
        /** @var list<array{id: mixed, boardId: mixed, tenantKey: mixed, createdAt: mixed, completedAt: mixed}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id', 'b.id AS boardId', 'b.tenantKey AS tenantKey', 't.createdAt AS createdAt', 't.completedAt AS completedAt')
            ->from(Task::class, 't')
            ->join('t.board', 'b')
            ->where('b.tenantKey IN (:keys)')
            ->andWhere('t.completedAt >= :from')
            ->andWhere('t.completedAt < :to')
            ->setParameter('keys', $keys)
            ->setParameter('from', $from, 'datetime_immutable')
            ->setParameter('to', $to, 'datetime_immutable')
            ->orderBy('t.completedAt', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
        $samples = [];
        foreach ($rows as $row) {
            if (is_string($row['tenantKey']) && ExactKey::oneOf($row['tenantKey'], $keys) && $row['createdAt'] instanceof DateTimeImmutable && $row['completedAt'] instanceof DateTimeImmutable) {
                $samples[] = new TaskCompletionSample((string) $row['id'], (string) $row['boardId'], $row['tenantKey'], $row['createdAt'], $row['completedAt']);
            }
        }

        return $samples;
    }

    public function firstEntries(array $tenantKeys, string $columnName, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array
    {
        $keys = $this->keys($tenantKeys);
        $this->window($from, $to);
        $this->limit($limit);
        if ($keys === [] || $columnName === '') {
            return [];
        }
        // Audit YJ-L5: the column name is compared byte for byte in PHP (the database may fold it), so the rows are
        // scanned in keyset batches by history id and the first exact entry of each task is kept.
        /** @var array<string, array{0: string, 1: DateTimeImmutable}> $first task id => [tenant key, first entry] */
        $first = [];
        $after = null;
        do {
            $builder = $this->entityManager->createQueryBuilder()
                ->select('h.id AS id', 'IDENTITY(h.task) AS taskId', 'b.tenantKey AS tenantKey', 'h.newValue AS name', 'h.createdAt AS at')
                ->from(TaskChangeHistory::class, 'h')
                ->join('h.task', 't')
                ->join('t.board', 'b')
                ->where('b.tenantKey IN (:keys)')
                ->andWhere('h.changeType = :column')
                ->andWhere('h.newValue = :name')
                ->andWhere('h.createdAt < :to')
                ->setParameter('keys', $keys)
                ->setParameter('column', TaskChangeType::Column->value)
                ->setParameter('name', $columnName)
                ->setParameter('to', $to, 'datetime_immutable')
                ->orderBy('h.id', 'ASC')
                ->setMaxResults($this->batchSize);
            if ($after !== null) {
                // @igor-ignore - A query builder created in this call; nothing is kept on the service.
                $builder->andWhere('h.id > :after')->setParameter('after', $after);
            }
            /** @var list<array{id: mixed, taskId: mixed, tenantKey: mixed, name: mixed, at: mixed}> $rows */
            $rows = $builder->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $taskId = is_scalar($row['taskId']) ? (string) $row['taskId'] : '';
                $exact  = $row['name'] === $columnName && is_string($row['tenantKey']) && ExactKey::oneOf($row['tenantKey'], $keys) && $row['at'] instanceof DateTimeImmutable;
                if ($exact && (!isset($first[$taskId]) || $row['at'] < $first[$taskId][1])) {
                    $first[$taskId] = [(string) $row['tenantKey'], $row['at']];
                }
            }
            $last  = $rows === [] ? null : $rows[array_key_last($rows)]['id'];
            $after = is_scalar($last) ? (string) $last : null;
        } while (count($rows) === $this->batchSize);

        $entries = [];
        foreach ($first as $taskId => [$tenantKey, $at]) {
            if ($at >= $from) {
                $entries[] = new TaskColumnEntry((string) $taskId, $tenantKey, $at);
            }
        }
        usort($entries, static fn (TaskColumnEntry $a, TaskColumnEntry $b): int => [$a->enteredAt, $a->taskId] <=> [$b->enteredAt, $b->taskId]);

        return array_slice($entries, 0, $limit);
    }

    /**
     * The (tenant key, date) of every task whose `$field` falls in the window, by keyset batches.
     *
     * @param list<string> $keys
     *
     * @return iterable<array{0: string, 1: DateTimeImmutable}>
     */
    private function scan(array $keys, string $field, DateTimeImmutable $from, DateTimeImmutable $to): iterable
    {
        $after = null;
        do {
            $builder = $this->entityManager->createQueryBuilder()
                ->select('t.id AS id', 'b.tenantKey AS tenantKey', 't.' . $field . ' AS at')
                ->from(Task::class, 't')
                ->join('t.board', 'b')
                ->where('b.tenantKey IN (:keys)')
                ->andWhere('t.' . $field . ' >= :from')
                ->andWhere('t.' . $field . ' < :to')
                ->setParameter('keys', $keys)
                ->setParameter('from', $from, 'datetime_immutable')
                ->setParameter('to', $to, 'datetime_immutable')
                ->orderBy('t.id', 'ASC')
                ->setMaxResults($this->batchSize);
            if ($after !== null) {
                $builder->andWhere('t.id > :after')->setParameter('after', $after);
            }
            /** @var list<array{id: mixed, tenantKey: mixed, at: mixed}> $rows */
            $rows = $builder->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                if (is_string($row['tenantKey']) && ExactKey::oneOf($row['tenantKey'], $keys) && $row['at'] instanceof DateTimeImmutable) {
                    yield [$row['tenantKey'], $row['at']];
                }
            }
            $last  = $rows === [] ? null : $rows[array_key_last($rows)]['id'];
            $after = is_scalar($last) ? (string) $last : null;
        } while (count($rows) === $this->batchSize);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function keys(array $keys): array
    {
        if (count($keys) > self::MAX_KEYS) {
            throw new InvalidArgumentException(sprintf('TaskBoard: at most %d tenant keys per call.', self::MAX_KEYS));
        }

        return BoardCriteria::forTenants($keys)->tenantKeys;
    }

    private function window(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        if ($from >= $to) {
            throw new InvalidArgumentException('TaskBoard: the window must start before it ends.');
        }
        if ($from->modify('+' . self::MAX_DAYS . ' days') < $to) {
            throw new InvalidArgumentException(sprintf('TaskBoard: a window is at most %d days long.', self::MAX_DAYS));
        }
    }

    private function limit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_SAMPLES) {
            throw new InvalidArgumentException(sprintf('TaskBoard: the limit must be between 1 and %d.', self::MAX_SAMPLES));
        }
    }
}
