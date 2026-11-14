<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeInUseException;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use Nowo\TaskBoardBundle\Support\ExactKey;

use function array_chunk;
use function array_filter;
use function array_values;
use function count;
use function sprintf;

/**
 * Since 2.1.0 (spec 214): reads the ledger, so a current number and an alias both resolve. No access check here: the caller
 * asks whether the viewer may read what it gets.
 */
final readonly class TaskNumberLedger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** The task of a number in a scope; null when it is unknown or retired. */
    public function find(string $scopeKey, int $number): ?Task
    {
        return $this->findInScopes([$scopeKey], $number)[0] ?? null;
    }

    /**
     * The tasks that carry or carried a number in any of the scopes (one query): at most one per scope. For a host's search
     * across the tenants a viewer may read.
     *
     * Since 2.15.1 (REQ-SEC-006, audit F5): only the ledger rows whose scope is exactly one asked; the database may fold
     * it (`Acme` for `acme`). A scope that cannot be matched exactly is ignored.
     *
     * @param list<string> $scopeKeys
     *
     * @return list<Task>
     */
    public function findInScopes(array $scopeKeys, int $number): array
    {
        $scopeKeys = array_values(array_filter($scopeKeys, static fn (string $key): bool => ExactKey::isUsable($key)));
        if ($scopeKeys === []) {
            return [];
        }

        $tasks = [];
        foreach (array_chunk($scopeKeys, ExactKey::CHUNK) as $chunk) {
            /** @var list<array{0: Task, scopeKey: string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('t', 'e.scopeKey AS scopeKey')
                ->from(Task::class, 't')
                ->innerJoin(TaskNumberEntry::class, 'e', 'WITH', 'e.task = t')
                ->where('e.scopeKey IN (:scopes)')
                ->andWhere('e.number = :number')
                ->setParameter('scopes', $chunk)
                ->setParameter('number', $number)
                ->getQuery()
                ->getResult();
            foreach ($rows as $row) {
                if (ExactKey::oneOf($row['scopeKey'], $chunk)) {
                    $tasks[] = $row[0];
                }
            }
        }

        return $tasks;
    }

    /**
     * Forgets a scope's counter and ledger, for a tenant that is destroyed and whose key is never reused (spec 214, FR-005: the
     * host decides when, after its tasks are gone). Audit HY-L2: while a task still has its current number in the scope it refuses,
     * because a new task would then get the same number. An alias of a task moved to another scope does not count.
     *
     * Since 2.15.1 (REQ-SEC-006 item 2, audit F5): the rows are selected first, PHP keeps those whose scope is exactly
     * `$scopeKey`, and they are deleted by primary key; `purgeScope('acme')` never touches `Acme`'s ledger or counter.
     *
     * @throws TaskNumberScopeInUseException
     * @throws InvalidArgumentException when the scope cannot be matched exactly
     */
    public function purgeScope(string $scopeKey): void
    {
        ExactKey::assertUsable($scopeKey, 'task-number scope');

        /** @var list<array{id: string, scopeKey: string, tenantKey: string|null, boardId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('t.id AS id', 'e.scopeKey AS scopeKey', 'b.tenantKey AS tenantKey', 'b.id AS boardId')
            ->from(Task::class, 't')
            ->innerJoin('t.board', 'b')
            ->innerJoin(TaskNumberEntry::class, 'e', 'WITH', 'e.task = t AND e.number = t.number')
            ->where('e.scopeKey = :scope')
            ->andWhere('b.tenantKey = :scope OR b.id = :scope')
            ->setParameter('scope', $scopeKey)
            ->getQuery()
            ->getArrayResult();
        $inUse = count(array_filter($rows, static fn (array $row): bool => $row['scopeKey'] === $scopeKey && ($row['tenantKey'] === $scopeKey || $row['boardId'] === $scopeKey)));
        if ($inUse > 0) {
            throw TaskNumberScopeInUseException::forScope($scopeKey, $inUse);
        }

        // @igor-ignore - A static closure run at once by transactional(): nothing is kept in the service.
        $this->entityManager->getConnection()->transactional(static function (Connection $db) use ($scopeKey): void {
            $numbers = [];
            foreach ($db->fetchAllAssociative(sprintf('SELECT scope_key, number FROM %s WHERE scope_key = ?', TaskNumberEntry::TABLE), [$scopeKey]) as $row) {
                if ($row['scope_key'] === $scopeKey) {
                    $numbers[] = (int) $row['number'];
                }
            }
            // The primary key is (scope_key, number): a folding collation holds one row per look-alike class and number,
            // and that row is the exact one kept above.
            foreach (array_chunk($numbers, ExactKey::CHUNK) as $chunk) {
                $db->executeStatement(sprintf('DELETE FROM %s WHERE scope_key = ? AND number IN (?)', TaskNumberEntry::TABLE), [$scopeKey, $chunk], [ParameterType::STRING, ArrayParameterType::INTEGER]);
            }
            if ($db->fetchOne(sprintf('SELECT scope_key FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), [$scopeKey]) === $scopeKey) {
                $db->delete(TaskNumberCounter::TABLE, ['scope_key' => $scopeKey]);
            }
        });
    }
}
