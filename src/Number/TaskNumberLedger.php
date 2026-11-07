<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;

use function array_values;

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
     * @param list<string> $scopeKeys
     *
     * @return list<Task>
     */
    public function findInScopes(array $scopeKeys, int $number): array
    {
        if ($scopeKeys === []) {
            return [];
        }

        /** @var list<Task> $tasks */
        $tasks = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->innerJoin(TaskNumberEntry::class, 'e', 'WITH', 'e.task = t')
            ->where('e.scopeKey IN (:scopes)')
            ->andWhere('e.number = :number')
            ->setParameter('scopes', array_values($scopeKeys))
            ->setParameter('number', $number)
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    /**
     * Forgets a scope's counter and ledger, for a tenant that is destroyed and whose key is never reused (spec 214, FR-005: the
     * host decides when). Deleting them while tasks remain would hand their numbers out again.
     */
    public function purgeScope(string $scopeKey): void
    {
        // @igor-ignore - A static closure run at once by transactional(): nothing is kept in the service.
        $this->entityManager->getConnection()->transactional(static function (Connection $db) use ($scopeKey): void {
            $db->delete(TaskNumberEntry::TABLE, ['scope_key' => $scopeKey]);
            $db->delete(TaskNumberCounter::TABLE, ['scope_key' => $scopeKey]);
        });
    }
}
