<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ExactKey;
use SortDirection;

use function array_filter;
use function array_values;

/**
 * Doctrine ORM implementation for task boards.
 */
final readonly class DoctrineOrmTaskBoardRepository implements TaskBoardRepositoryInterface, TenantBoardSlugLookupInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function save(TaskBoard $board): void
    {
        $this->entityManager->persist($board);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function remove(TaskBoard $board): void
    {
        $this->entityManager->remove($board);
        RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
    }

    public function findById(string $id): ?TaskBoard
    {
        $board = $this->entityManager->find(TaskBoard::class, $id);

        return $board instanceof TaskBoard ? $board : null;
    }

    public function findAllActive(): array
    {
        /** @var list<TaskBoard> $boards */
        $boards = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL')
            ->orderBy('b.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $boards;
    }

    /**
     * Since 2.15.1 (REQ-SEC-006, audit F5): only the boards whose tenant key is exactly `$tenantKey`; the database may fold
     * it (`Acme`, `acmé`, `acme `), so PHP keeps the exact ones. A key that cannot be matched exactly names no board.
     */
    public function findActiveByTenant(string $tenantKey): array
    {
        if (!ExactKey::isUsable($tenantKey)) {
            return [];
        }

        /** @var list<TaskBoard> $boards */
        $boards = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL')
            ->andWhere('b.tenantKey = :tenantKey')
            ->setParameter('tenantKey', $tenantKey)
            ->orderBy('b.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($boards, static fn (TaskBoard $board): bool => $board->getTenantKey() === $tenantKey));
    }

    /**
     * Since 2.15.1 (REQ-SEC-006 item 6): the exact match happens before the first row is taken, so a look-alike tenant's
     * older board never hides this tenant's own.
     */
    public function findOneByTenant(string $tenantKey): ?TaskBoard
    {
        if (!ExactKey::isUsable($tenantKey)) {
            return null;
        }

        /** @var list<array{id: string, tenantKey: string|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('b.id AS id', 'b.tenantKey AS tenantKey')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL')
            ->andWhere('b.tenantKey = :tenantKey')
            ->setParameter('tenantKey', $tenantKey)
            ->orderBy('b.createdAt', SortDirection::Ascending)
            ->addOrderBy('b.id', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            if ($row['tenantKey'] === $tenantKey) {
                return $this->findById($row['id']);
            }
        }

        return null;
    }

    /** Since 2.15.1 (REQ-SEC-006): slug and tenant key both exact. */
    public function findBySlugInTenant(string $slug, ?string $tenantKey): ?TaskBoard
    {
        if ($tenantKey !== null && !ExactKey::isUsable($tenantKey)) {
            return null;
        }

        return $this->exactSlug($this->entityManager->getRepository(TaskBoard::class)->findBy(['slug' => $slug, 'tenantKey' => $tenantKey]), $slug, $tenantKey, true);
    }

    /** Since 2.15.1 (REQ-SEC-006): the slug exact. */
    public function findBySlug(string $slug): ?TaskBoard
    {
        return $this->exactSlug($this->entityManager->getRepository(TaskBoard::class)->findBy(['slug' => $slug]), $slug, null, false);
    }

    /**
     * The first board whose slug (and, with `$checkTenant`, tenant key) is exactly the one asked.
     *
     * @param array<mixed> $boards
     */
    private function exactSlug(array $boards, string $slug, ?string $tenantKey, bool $checkTenant): ?TaskBoard
    {
        foreach ($boards as $board) {
            if ($board instanceof TaskBoard && $board->getSlug() === $slug && (!$checkTenant || $board->getTenantKey() === $tenantKey)) {
                return $board;
            }
        }

        return null;
    }
}
