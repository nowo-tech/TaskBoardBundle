<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\View;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;

use function array_chunk;
use function array_is_list;
use function array_map;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function str_contains;

use const FILTER_VALIDATE_EMAIL;

/**
 * Since 1.27.0 (TB-SYNC-3): the typed custom fields of a task for Twig — `task_board_sync_fields(task)` (the task panel)
 * and `task_board_sync_search_texts(tasks)` (the list search: only the rows drawn are read, never `remote_meta`).
 */
final readonly class SyncFieldViewProvider
{
    private const CHUNK = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?SyncPeopleViewProvider $people = null,
    ) {
    }

    /**
     * The task's fields in the provider's order: name, type (a {@see SyncFieldType}
     * value; `text` for a value that does not fit the field's type), the typed value, its text, and for choices their
     * names and colours. An erased value is left out.
     *
     * @return list<array{name: string, type: string, value: mixed, text: string, choices: list<array{id: string, name: string, color: string|null}>, config: array<string, int|string>}>
     */
    public function fieldsOf(Task $task): array
    {
        /** @var list<TaskSyncFieldValue> $values */
        $values = $this->entityManager->createQueryBuilder()
            ->select('v', 'f')->from(TaskSyncFieldValue::class, 'v')->join('v.field', 'f')
            ->where('v.task = :task')->setParameter('task', $task)
            ->orderBy('f.position', 'ASC')->addOrderBy('f.name', 'ASC')
            ->getQuery()->getResult();
        $fields = [];
        foreach ($values as $value) {
            if ($value->isErased()) {
                continue;
            }
            $field    = $value->getField();
            $selected = $value->getValue();
            $type     = $this->fits($field->getType(), $selected) ? $field->getType() : SyncFieldType::Text;
            $ids      = is_string($selected) ? [$selected] : (is_array($selected) ? $selected : []);
            $choices  = [];
            foreach ($type === SyncFieldType::Text ? [] : $field->getChoices() as $choice) {
                if (in_array($choice['id'], $ids, true)) {
                    $choices[] = $choice;
                }
            }
            $fields[] = [
                'name'    => $field->getName(),
                'type'    => $type->value,
                'value'   => $selected,
                'text'    => $type === SyncFieldType::People ? $this->peopleText($selected) : $value->getText(),
                'choices' => $choices,
                'config'  => $field->getConfig(),
            ];
        }

        return $fields;
    }

    /**
     * What the list's client-side search finds for each task drawn: its custom field values and custom id, one line.
     *
     * @param iterable<Task> $tasks
     *
     * @return array<string, string> task id => text (tasks without any are left out)
     */
    public function searchTextsOf(iterable $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = $task->getId();
        }
        $texts = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            /** @var list<array{taskId: string, text: string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(v.task) AS taskId', 'v.text AS text')->from(TaskSyncFieldValue::class, 'v')
                ->where('v.task IN (:tasks)')->andWhere("v.text <> ''")->setParameter('tasks', $chunk)
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $texts[(string) $row['taskId']][] = $row['text'];
            }
            // A people field keeps references: the names are read from the directory.
            /** @var list<array{taskId: string, value: mixed}> $people */
            $people = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(v.task) AS taskId', 'v.value AS value')->from(TaskSyncFieldValue::class, 'v')
                ->join(TaskSyncField::class, 'f', 'WITH', 'f = v.field')
                ->where('v.task IN (:tasks)')->andWhere('f.type = :people')
                ->setParameter('tasks', $chunk)->setParameter('people', SyncFieldType::People->value)
                ->getQuery()->getArrayResult();
            foreach ($people as $row) {
                $texts[(string) $row['taskId']][] = $this->peopleText($row['value']);
            }
            /** @var list<array{taskId: string, customId: string}> $items */
            $items = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(i.task) AS taskId', 'i.customId AS customId')->from(TaskSyncItem::class, 'i')
                ->where('i.task IN (:tasks)')->andWhere('i.customId IS NOT NULL')->setParameter('tasks', $chunk)
                ->getQuery()->getArrayResult();
            foreach ($items as $item) {
                $texts[(string) $item['taskId']][] = $item['customId'];
            }
        }

        return array_map(static fn (array $parts): string => implode(' ', $parts), $texts);
    }

    /**
     * Whether a stored value is of the field's type. A value the provider's adapter refused is stored as a fallback,
     * and the definition may say anything: nothing here trusts it for a link.
     */
    private function fits(SyncFieldType $type, mixed $value): bool
    {
        if (is_array($value) && isset($value['fallback'])) {
            return false;
        }

        return match ($type) {
            SyncFieldType::Url   => is_string($value) && preg_match('~^https?://~i', $value) === 1,
            SyncFieldType::Email => is_string($value) && !str_contains($value, '?') && !str_contains($value, '&') && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            default              => true,
        };
    }

    private function peopleText(mixed $tokens): string
    {
        return implode(', ', array_map(fn (mixed $token): string => $this->people?->personLabel((string) $token) ?? '', is_array($tokens) && array_is_list($tokens) ? $tokens : []));
    }
}
