<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Import;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Support\ExactKey;

use function array_chunk;
use function array_filter;
use function array_values;

/**
 * Since 1.22: {@see TaskImportLinkLookupInterface} through Doctrine — one query per 500 ids on the task links of the
 * other boards of the tenant (index `idx_task_board_task_links_import`); only links an import wrote (`import_source`) count.
 */
final readonly class DoctrineTaskImportLinkLookup implements TaskImportLinkLookupInterface
{
    private const CHUNK = 500;

    /** The length of `task_board_task_links.external_id`. */
    private const EXTERNAL_ID_LENGTH = 128;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Since 2.15.1 (REQ-SEC-006, audit F5): the database may fold the external id, the scope and the tenant key
     * (`ABC-1` for `abc-1`, `Acme` for `acme`); PHP keeps the rows where all three are exactly the ones asked. An id that
     * cannot be matched exactly is never found.
     */
    public function findOnOtherBoards(TaskBoard $board, string $scope, array $externalIds): array
    {
        $tenantKey = $board->getTenantKey();
        $wanted    = array_values(array_filter($externalIds, static fn (string $id): bool => ExactKey::isUsable($id, self::EXTERNAL_ID_LENGTH)));
        $found     = [];
        foreach (array_chunk($wanted, self::CHUNK) as $chunk) {
            $builder = $this->entityManager->createQueryBuilder()
                ->select('l.externalId AS externalId', 'l.importSource AS importSource', 'b.tenantKey AS tenantKey')
                ->from(TaskLink::class, 'l')
                ->join('l.task', 't')
                ->join('t.board', 'b')
                ->where('l.externalId IN (:ids)')
                ->andWhere('b.id <> :board')
                ->andWhere('l.importSource = :scope')
                ->setParameter('ids', $chunk)
                ->setParameter('board', $board->getId())
                ->setParameter('scope', $scope);
            if ($tenantKey === null) {
                $builder->andWhere('b.tenantKey IS NULL');
            } else {
                $builder->andWhere('b.tenantKey = :tenant')->setParameter('tenant', $tenantKey);
            }

            /** @var list<array{externalId: string, importSource: string|null, tenantKey: string|null}> $rows */
            $rows = $builder->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                if ($row['importSource'] === $scope && ExactKey::same($row['tenantKey'], $tenantKey) && ExactKey::oneOf($row['externalId'], $chunk)) {
                    $found[$row['externalId']] = true;
                }
            }
        }

        return $found;
    }
}
