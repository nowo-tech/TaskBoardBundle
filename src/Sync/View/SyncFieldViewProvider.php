<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\View;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncField;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Symfony\Contracts\Service\ResetInterface;

use function implode;
use function in_array;
use function is_array;
use function is_string;

/**
 * Since 1.27.0 (TB-SYNC-3): the typed custom fields of a task for Twig — `task_board_sync_fields(task)` (the task panel)
 * and `task_board_sync_search_text(task)` (the list search: every value of the board's tasks is read with one query
 * per board and request). Memoised per request (cleared by `kernel.reset`).
 */
final class SyncFieldViewProvider implements ResetInterface
{
    /** @var array<string, array<string, list<string>>> board id => task id => texts */
    private array $search = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * The task's fields in the provider's order: name, type (a {@see SyncFieldType}
     * value), the typed value, its text, and for choices their names and colours.
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
            $field    = $value->getField();
            $selected = $value->getValue();
            $ids      = is_string($selected) ? [$selected] : (is_array($selected) ? $selected : []);
            $choices  = [];
            foreach ($field->getChoices() as $choice) {
                if (in_array($choice['id'], $ids, true)) {
                    $choices[] = $choice;
                }
            }
            $fields[] = [
                'name'    => $field->getName(),
                'type'    => $field->getType()->value,
                'value'   => $selected,
                'text'    => $value->getText(),
                'choices' => $choices,
                'config'  => $field->getConfig(),
            ];
        }

        return $fields;
    }

    /** Every custom field value of the task as one line (for the list's client-side search). */
    public function searchTextOf(Task $task): string
    {
        $boardId = $task->getBoard()->getId();
        if (!isset($this->search[$boardId])) {
            /** @var list<array{taskId: string, text: string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(v.task) AS taskId', 'v.text AS text')->from(TaskSyncFieldValue::class, 'v')
                ->join(TaskSyncField::class, 'f', 'WITH', 'f = v.field')
                ->where('f.board = :board')->setParameter('board', $task->getBoard())
                ->getQuery()->getArrayResult();
            $this->search[$boardId] = [];
            foreach ($rows as $row) {
                $this->search[$boardId][(string) $row['taskId']][] = $row['text'];
            }
        }

        return implode(' ', $this->search[$boardId][$task->getId()] ?? []);
    }

    public function reset(): void
    {
        $this->search = [];
    }
}
