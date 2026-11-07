<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Doctrine\ORM\EntityManagerInterface;
use Generator;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function json_encode;
use function mb_stripos;
use function mb_strlen;
use function preg_grep;
use function preg_match;
use function str_starts_with;

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

    private const FIELD_LINE = '/^- \*\*.+?\*\*: /';

    private const FIELD_LINE_PARTS = '/^- \*\*(.+?)\*\*: (.*)$/';

    /** `task_board.sync.fields_document` in the bundle's locales, then the label of the provider the bundle ships. */
    private const LEGACY_TITLE = '/^(?:Fields|Campos|Felder|Champs|Campi|Velden) \(ClickUp\)$/D';

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
        $erased = $this->eraseRows($this->matching($value, $board));

        // Audit GWd-L4: a line left in a "Fields" document with no row behind it (`text` mode) counts as one value.
        return $erased + $this->eraseDocuments($board, static fn (string $line): bool => mb_stripos($line, $value) !== false);
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
        $erased = $this->eraseRows($this->rows($board, $types));

        // Audit GWd-L4: a type cannot be told from a document's line, so `text` mode is only erased as a whole.
        return $erased + ($types === null ? $this->eraseDocuments($board, static fn (string $line): bool => preg_match(self::FIELD_LINE, $line) === 1) : 0);
    }

    /**
     * Since 1.29.0: erases one field of one task, without knowing the value. For an erasure log that keeps no value: after
     * `kernel.secret` changes, or when a value came back from the provider, the host repeats its log with this. The host
     * authorises the caller.
     *
     * @return int the values erased
     */
    public function eraseField(Task $task, string $fieldName): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('v.id AS id', 'v.value AS value')
            ->from(TaskSyncFieldValue::class, 'v')->join(TaskSyncField::class, 'f', 'WITH', 'f = v.field')
            ->where('v.task = :task')->andWhere('f.name = :name')->setParameter('task', $task)->setParameter('name', $fieldName);
        /** @var list<array{id: string, value: mixed}> $rows */
        $rows   = array_values(array_filter($query->getQuery()->getArrayResult(), static fn (array $row): bool => !is_array($row['value']) || !isset($row['value']['erased'])));
        $erased = $this->eraseRows($rows);
        // In `text` mode the document is the only copy; a line a row already took is not counted twice.
        $lines = $this->eraseDocumentLines($task, static fn (string $line): bool => str_starts_with($line, '- **' . $fieldName . '**: '));
        $this->entityManager->flush();

        return $erased + $lines;
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
        $seen = [];
        foreach ($this->matching($value, $board) as $row) {
            $seen[$row['taskId'] . '|' . $row['field']] = true;
            yield $this->exported($row);
        }
        // Audit HI-L3: what the eraser can find, an access request finds too.
        yield from $this->documentRows($board, $value, $seen);
    }

    /**
     * Every value of a board.
     *
     * @return Generator<int, array{boardId: string, taskId: string, field: string, type: string, text: string, value: mixed}>
     */
    public function exportBoard(TaskBoard $board): Generator
    {
        $seen = [];
        foreach ($this->rows($board, null) as $row) {
            $seen[$row['taskId'] . '|' . $row['field']] = true;
            yield $this->exported($row);
        }
        yield from $this->documentRows($board, null, $seen);
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
                // Audit GWd-L4: in `both` mode the same field is a line of the "Fields" document.
                $this->eraseDocumentLines($value->getTask(), static fn (string $line): bool => str_starts_with($line, '- **' . $value->getField()->getName() . '**: '));
                ++$erased;
            }
        }
        $this->entityManager->flush();

        return $erased;
    }

    /**
     * Audit HI-L2: the sync's "Fields" document: marked when the sync writes it. One written before the mark is recognised
     * only strictly: the title the sync gives it in any locale and nothing but `- **name**: value` lines. Anything else is
     * somebody's document and is never touched.
     */
    private function isFieldsDocument(TaskDocument $document): bool
    {
        if ($document->isSyncFields()) {
            return true;
        }
        $lines = explode("\n", $document->getContent());

        return preg_match(self::LEGACY_TITLE, $document->getTitle()) === 1 && $lines === preg_grep(self::FIELD_LINE, $lines);
    }

    /**
     * Removes the matching lines and keeps a suppression record of each, so a re-sync does not write them again (audit
     * HI-L3). Returns the lines removed.
     *
     * @param callable(string): bool $matches
     */
    private function eraseDocumentLines(Task $task, callable $matches): int
    {
        $erased = 0;
        foreach ($task->getDocuments() as $document) {
            if (!$this->isFieldsDocument($document)) {
                continue;
            }
            $lines   = explode("\n", $document->getContent());
            $kept    = array_values(array_filter($lines, static fn (string $line): bool => !$matches($line)));
            $removed = array_values(array_diff($lines, $kept));
            if ($removed !== []) {
                $document->markSyncFields()->addErasedLines(array_map($this->suppression->recordLine(...), $removed));
                $document->setContent(implode("\n", $kept));
                $erased += count($removed);
            }
        }

        return $erased;
    }

    /**
     * Audit GWd-L4: `text` mode writes no field rows: the document is the only copy.
     *
     * @param callable(string): bool $matches
     */
    private function eraseDocuments(?TaskBoard $board, callable $matches): int
    {
        $erased = 0;
        foreach ($this->tasksWithDocuments($board) as $task) {
            $erased += $this->eraseDocumentLines($task, $matches);
        }
        $this->entityManager->flush();

        return $erased;
    }

    /**
     * Audit HI-L3: the lines of the "Fields" documents as export rows, except a field the rows already gave for that task.
     *
     * @param array<string, true> $seen task id + field
     *
     * @return Generator<int, array{boardId: string, taskId: string, field: string, type: string, text: string, value: mixed}>
     */
    private function documentRows(?TaskBoard $board, ?string $value, array $seen): Generator
    {
        foreach ($this->tasksWithDocuments($board) as $task) {
            foreach ($task->getDocuments() as $document) {
                if (!$this->isFieldsDocument($document)) {
                    continue;
                }
                foreach (explode("\n", $document->getContent()) as $line) {
                    if (preg_match(self::FIELD_LINE_PARTS, $line, $parts) !== 1 || isset($seen[$task->getId() . '|' . $parts[1]]) || ($value !== null && mb_stripos($line, $value) === false)) {
                        continue;
                    }
                    yield ['boardId' => $task->getBoard()->getId(), 'taskId' => $task->getId(), 'field' => $parts[1], 'type' => SyncFieldType::Text->value, 'text' => $parts[2], 'value' => $parts[2]];
                }
            }
        }
    }

    /**
     * Tasks with a document that may be the sync's, a page at a time.
     *
     * @return Generator<int, Task>
     */
    private function tasksWithDocuments(?TaskBoard $board): Generator
    {
        $after = '';
        do {
            $query = $this->entityManager->createQueryBuilder()->select('t')->from(Task::class, 't')->join(TaskDocument::class, 'd', 'WITH', 'd.task = t')
                ->where('t.id > :after')->andWhere('d.syncFields IS NOT NULL OR d.content LIKE :shape')->setParameter('after', $after)->setParameter('shape', '- **%')
                ->groupBy('t.id')->orderBy('t.id', 'ASC')->setMaxResults(self::PAGE);
            if ($board instanceof TaskBoard) {
                $query->andWhere('t.board = :board')->setParameter('board', $board);
            }
            /** @var list<Task> $tasks */
            $tasks = $query->getQuery()->getResult();
            foreach ($tasks as $task) {
                $after = $task->getId();
                yield $task;
            }
        } while (count($tasks) === self::PAGE);
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
