<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Generator;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldValueEraser;
use SortDirection;

use function addcslashes;
use function array_filter;
use function array_values;
use function count;
use function is_array;
use function is_scalar;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function str_contains;

/**
 * Since 3.3.0 (CU-3): access and erasure for **native** custom field values (RGPD Art. 15, 17, 20). A text value may
 * name a person (typed here or imported from a provider), so a data-subject request reaches it as it reaches the
 * synced fields ({@see SyncFieldValueEraser}). **The host authorises the caller.**.
 *
 * - {@see self::exportByValue()} / {@see self::exportBoard()}: rows of board id, task id, field id and name, type, the
 *   value as people read it and the field's provenance marker (`clickup:<id>` for an imported field).
 * - {@see self::eraseByValue()}: removes the text values holding the needle. An imported value erased here is not written
 *   again by a re-import while the provider keeps the same value: the import's rule never overwrites a value changed
 *   here (an erasure is such a change). Clear it at the source too.
 *
 * Not reached: the task history rows of earlier values (`custom_field` changes keep the old and new value as text) and
 * the provider's own copy; see SECURITY.md. Stateless (FrankenPHP worker mode).
 */
final readonly class TaskCustomFieldPersonalData
{
    private const PAGE = 500;

    private const MIN_NEEDLE = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?TaskBoardEntityManager $rows = null,
    ) {
    }

    /**
     * The text values holding `$value` (case-insensitive), for an access request. A needle of fewer than three characters
     * finds nothing.
     *
     * @param TaskBoard|null $board one board; **null = every board of every tenant** (a multi-tenant host passes it)
     *
     * @return Generator<int, array{boardId: string, taskId: string, fieldId: string, field: string, type: string, value: string, source: string|null}>
     */
    public function exportByValue(string $value, ?TaskBoard $board): Generator
    {
        foreach ($this->matching($value, $board) as $row) {
            yield $this->exported($row);
        }
    }

    /**
     * Every native value of a board's tasks, read in pages of 500 (plain rows: the entity manager keeps nothing).
     *
     * @return Generator<int, array{boardId: string, taskId: string, fieldId: string, field: string, type: string, value: string, source: string|null}>
     */
    public function exportBoard(TaskBoard $board): Generator
    {
        $board = $this->own($board);
        $after = '';
        do {
            /** @var list<array<string, mixed>> $page */
            $page = $this->query($board)->andWhere('v.id > :after')->setParameter('after', $after)->setMaxResults(self::PAGE)->getQuery()->getArrayResult();
            foreach ($page as $row) {
                $after = self::str($row['id']);
                yield $this->exported($row);
            }
        } while (count($page) === self::PAGE);
    }

    /**
     * Erases the text values holding `$value` (case-insensitive); a needle of fewer than three characters erases nothing.
     *
     * @param TaskBoard|null $board one board; **null = every board of every tenant**
     *
     * @return int the values erased
     */
    public function eraseByValue(string $value, ?TaskBoard $board): int
    {
        $erased = 0;
        foreach ($this->matching($value, $board) as $row) {
            $stored = $this->entityManager->find(TaskCustomFieldValue::class, $row['id']);
            if ($stored instanceof TaskCustomFieldValue) {
                $this->entityManager->remove($stored);
                ++$erased;
            }
        }
        $this->entityManager->flush();

        return $erased;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function matching(string $value, ?TaskBoard $board): array
    {
        if (mb_strlen($value) < self::MIN_NEEDLE) {
            return [];
        }
        $needle = mb_strtolower($value);
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->query($board instanceof TaskBoard ? $this->own($board) : null)
            ->andWhere('f.type = :text')->setParameter('text', CustomFieldType::Text->value)
            ->andWhere("LOWER(v.textValue) LIKE :needle ESCAPE '!'")->setParameter('needle', '%' . addcslashes($needle, '%_!') . '%')
            ->getQuery()->getArrayResult();

        // The database's LIKE may fold accents or case otherwise: the needle is checked again here.
        return array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower(self::str($row['textValue'])), $needle)));
    }

    private function query(?TaskBoard $board): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('v.id AS id', 'v.textValue AS textValue', 'v.numberValue AS numberValue', 'f.id AS fieldId', 'f.name AS field', 'f.type AS type', 'f.options AS options', 'f.sourceRef AS source', 't.id AS taskId', 'IDENTITY(t.board) AS boardId')
            ->from(TaskCustomFieldValue::class, 'v')->join('v.field', 'f')->join('v.task', 't')
            ->orderBy('v.id', SortDirection::Ascending);
        if ($board instanceof TaskBoard) {
            $query->andWhere('IDENTITY(t.board) = :board')->setParameter('board', $board->getId());
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{boardId: string, taskId: string, fieldId: string, field: string, type: string, value: string, source: string|null}
     */
    private function exported(array $row): array
    {
        $type  = $row['type'] instanceof CustomFieldType ? $row['type'] : CustomFieldType::from(self::str($row['type']));
        $value = match ($type) {
            CustomFieldType::Text     => self::str($row['textValue']),
            CustomFieldType::Number   => TaskCustomFieldManager::trimDecimal(self::str($row['numberValue'])),
            CustomFieldType::Dropdown => $this->optionName($row['options'], self::str($row['textValue'])),
        };

        return [
            'boardId' => self::str($row['boardId']),
            'taskId'  => self::str($row['taskId']),
            'fieldId' => self::str($row['fieldId']),
            'field'   => self::str($row['field']),
            'type'    => $type->value,
            'value'   => $value,
            'source'  => is_string($row['source']) ? $row['source'] : null,
        ];
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function optionName(mixed $options, string $id): string
    {
        foreach (is_array($options) ? $options : [] as $option) {
            if (is_array($option) && ($option['id'] ?? null) === $id) {
                return self::str($option['name'] ?? null);
            }
        }

        return '';
    }

    private function own(TaskBoard $board): TaskBoard
    {
        return $this->rows instanceof TaskBoardEntityManager ? $this->rows->attach($board) : $board;
    }
}
