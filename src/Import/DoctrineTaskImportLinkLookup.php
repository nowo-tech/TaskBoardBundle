<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;

use function array_chunk;
use function array_values;

/**
 * Since 1.22: {@see TaskImportLinkLookupInterface} through Doctrine — one query per 500 ids on the task links of the
 * other boards of the tenant (index `idx_task_board_task_links_import`); only links an import wrote (`import_source`) count.
 */
final readonly class DoctrineTaskImportLinkLookup implements TaskImportLinkLookupInterface
{
    private const CHUNK = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOnOtherBoards(TaskBoard $board, string $scope, array $externalIds): array
    {
        $found = [];
        foreach (array_chunk(array_values($externalIds), self::CHUNK) as $chunk) {
            $builder = $this->entityManager->createQueryBuilder()
                ->select('l.externalId AS externalId')
                ->from(TaskLink::class, 'l')
                ->join('l.task', 't')
                ->join('t.board', 'b')
                ->where('l.externalId IN (:ids)')
                ->andWhere('b.id <> :board')
                ->andWhere('l.importSource = :scope')
                ->setParameter('ids', $chunk)
                ->setParameter('board', $board->getId())
                ->setParameter('scope', $scope);
            $tenantKey = $board->getTenantKey();
            if ($tenantKey === null) {
                $builder->andWhere('b.tenantKey IS NULL');
            } else {
                $builder->andWhere('b.tenantKey = :tenant')->setParameter('tenant', $tenantKey);
            }

            /** @var list<array{externalId: string}> $rows */
            $rows = $builder->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $found[$row['externalId']] = true;
            }
        }

        return $found;
    }
}
