<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Doctrine\ORM\EntityManagerInterface;
use Generator;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function count;
use function in_array;
use function is_array;
use function json_encode;
use function mb_stripos;
use function mb_strlen;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.27.0 (audit GW-M3): erasure and access for custom field values, which are task content: `anonymise()` does
 * not reach them. For the host's data-subject requests (RGPD Art. 15, 17, 20): **the host authorises the caller**. An
 * erased value does not come back on a re-sync while the provider still has the same one (a suppression record stays,
 * {@see SyncFieldSuppression}); clear it there too. Not the "Fields" document of the `text` and `both` modes.
 */
final readonly class SyncFieldValueEraser
{
    private const PAGE = 500;

    private const MIN_NEEDLE = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SyncFieldSuppression $suppression,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Erases every value holding `$value` (case-insensitive, anywhere in it; also past the 500 characters of the text
     * column).
     *
     * @param TaskBoard|null $board one board; **null = every board of every tenant**, which a multi-tenant host only
     *                              passes for a request it has checked across tenants
     *
     * @return int the values erased
     */
    public function eraseByValue(string $value, ?TaskBoard $board): int
    {
        return $this->eraseRows($this->matching($value, $board));
    }

    /**
     * Erases the values of a board, for example after unlinking it: all, or those of some types.
     *
     * @param list<SyncFieldType>|null $types null = every type
     *
     * @return int the values erased
     */
    public function eraseBoard(TaskBoard $board, ?array $types = null): int
    {
        return $this->eraseRows($this->rows($board, $types));
    }

    /**
     * The values holding `$value`, for an access request.
     *
     * @param TaskBoard|null $board one board; **null = every board of every tenant**, as in {@see self::eraseByValue()}
     *
     * @return Generator<int, array{boardId: string, taskId: string, field: string, type: string, text: string, value: mixed}>
     */
    public function exportByValue(string $value, ?TaskBoard $board): Generator
    {
        foreach ($this->matching($value, $board) as $row) {
            yield $this->exported($row);
        }
    }

    /**
     * Every value of a board.
     *
     * @return Generator<int, array{boardId: string, taskId: string, field: string, type: string, text: string, value: mixed}>
     */
    public function exportBoard(TaskBoard $board): Generator
    {
        foreach ($this->rows($board, null) as $row) {
            yield $this->exported($row);
        }
    }

    /**
     * @param array{id: string, boardId: string, taskId: string, field: string, type: string|SyncFieldType, text: string, value: mixed} $row
     *
     * @return array{boardId: string, taskId: string, field: string, type: string, text: string, value: mixed}
     */
    private function exported(array $row): array
    {
        $type = $row['type'];

        return ['boardId' => $row['boardId'], 'taskId' => $row['taskId'], 'field' => $row['field'], 'type' => $type instanceof SyncFieldType ? $type->value : $type, 'text' => $row['text'], 'value' => $row['value']];
    }

    /**
     * @param iterable<array{id: string}> $rows
     */
    private function eraseRows(iterable $rows): int
    {
        $erased = 0;
        foreach ($rows as $row) {
            $value = $this->entityManager->find(TaskSyncFieldValue::class, $row['id']);
            if ($value instanceof TaskSyncFieldValue) {
                $value->erase($this->suppression->record($value->getValue(), $value->getText()), $this->clock->now());
                ++$erased;
            }
        }
        $this->entityManager->flush();

        return $erased;
    }

    /**
     * @return Generator<int, array{id: string, boardId: string, taskId: string, field: string, type: string|SyncFieldType, text: string, value: mixed}>
     */
    private function matching(string $value, ?TaskBoard $board): Generator
    {
        if (mb_strlen($value) < self::MIN_NEEDLE) {
            throw new InvalidArgumentException('The value to look for is too short.');
        }
        foreach ($this->rows($board, null) as $row) {
            if (mb_stripos($row['text'] . ' ' . json_encode($row['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value) !== false) {
                yield $row;
            }
        }
    }

    /**
     * Every value not yet erased, a page at a time (never the whole table in memory).
     *
     * @param list<SyncFieldType>|null $types
     *
     * @return Generator<int, array{id: string, boardId: string, taskId: string, field: string, type: string|SyncFieldType, text: string, value: mixed}>
     */
    private function rows(?TaskBoard $board, ?array $types): Generator
    {
        $after = '';
        do {
            $query = $this->entityManager->createQueryBuilder()
                ->select('v.id AS id', 'IDENTITY(f.board) AS boardId', 'IDENTITY(v.task) AS taskId', 'f.name AS field', 'f.type AS type', 'v.text AS text', 'v.value AS value')
                ->from(TaskSyncFieldValue::class, 'v')->join(TaskSyncField::class, 'f', 'WITH', 'f = v.field')
                ->where('v.id > :after')->setParameter('after', $after)
                ->orderBy('v.id', 'ASC')->setMaxResults(self::PAGE);
            if ($board instanceof TaskBoard) {
                $query->andWhere('f.board = :board')->setParameter('board', $board);
            }
            /** @var list<array{id: string, boardId: string, taskId: string, field: string, type: string|SyncFieldType, text: string, value: mixed}> $page */
            $page = $query->getQuery()->getArrayResult();
            foreach ($page as $row) {
                $after  = $row['id'];
                $type   = $row['type'] instanceof SyncFieldType ? $row['type'] : SyncFieldType::from($row['type']);
                $erased = is_array($row['value']) && isset($row['value']['erased']);
                if (!$erased && ($types === null || in_array($type, $types, true))) {
                    yield $row;
                }
            }
        } while (count($page) === self::PAGE);
    }
}
