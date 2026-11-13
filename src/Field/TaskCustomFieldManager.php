<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Doctrine\RecoveringFlusher;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructurePolicyInterface;
use Nowo\TaskBoardBundle\Port\Snapshots;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Throwable;

use function abs;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function in_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function max;
use function mb_strlen;
use function number_format;
use function rtrim;
use function str_contains;
use function strtolower;
use function trim;
use function usort;

/**
 * Since 2.17.0 (Beacon spec 234, FR-028): native custom fields — definitions per tenant or per board, values per task
 * ({@see self::setField()}), and the operator filters of the Task API and the list view ({@see self::filter()}).
 *
 * Definitions are written under {@see TaskBoardRestructurePolicyInterface} (a tenant's: `mayManageFolders()`; a board's:
 * `mayRestructureBoard()` — by default the manage right); a value needs the caller's edit right on the task, which the
 * caller checks (the API guard, the task form). Every write is a transaction of its own (a savepoint inside the caller's);
 * a failed flush leaves the entity manager open. Tenant keys are compared byte for byte. Stateless.
 */
final readonly class TaskCustomFieldManager
{
    public const TEXT_MAX = 5000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardRestructurePolicyInterface $policy,
        private TaskChangeRecorder $changeRecorder,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * The fields of a board: its tenant's, then its own, each by position then name.
     *
     * @return list<TaskCustomField>
     */
    public function fieldsFor(TaskBoard $board): array
    {
        return $this->fieldsForBoards([$board])[$board->getId()];
    }

    /**
     * The fields of several boards in one query (a page of tasks across boards), by board id, as {@see self::fieldsFor()}.
     *
     * @param list<TaskBoard> $boards
     *
     * @return array<string, list<TaskCustomField>>
     */
    public function fieldsForBoards(array $boards): array
    {
        $byBoard = [];
        $tenants = [];
        $none    = false;
        foreach ($boards as $board) {
            $byBoard[$board->getId()]               = [];
            $board->getTenantKey() === null ? $none = true : $tenants[$board->getTenantKey()] = true;
        }
        if ($byBoard === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()->select('f')->from(TaskCustomField::class, 'f')
            ->where('IDENTITY(f.board) IN (:boards)')->setParameter('boards', array_keys($byBoard));
        if ($tenants !== []) {
            $query->orWhere('f.board IS NULL AND f.tenantKey IN (:tenants)')->setParameter('tenants', array_map('strval', array_keys($tenants)));
        }
        if ($none) {
            $query->orWhere('f.board IS NULL AND f.tenantKey IS NULL');
        }
        /** @var list<TaskCustomField> $fields */
        $fields = $query->getQuery()->getResult();
        foreach ($boards as $board) {
            $byBoard[$board->getId()] = self::sorted(array_values(array_filter($fields, static fn (TaskCustomField $field): bool => $field->getTenantKey() === $board->getTenantKey() && ($field->getBoardId() === null || $field->getBoardId() === $board->getId()))));
        }

        return $byBoard;
    }

    /**
     * @param list<TaskCustomField> $fields
     *
     * @return list<TaskCustomField>
     */
    private static function sorted(array $fields): array
    {
        usort($fields, static fn (TaskCustomField $a, TaskCustomField $b): int => [$a->getBoardId() !== null, $a->getPosition(), $a->getName(), $a->getId()] <=> [$b->getBoardId() !== null, $b->getPosition(), $b->getName(), $b->getId()]);

        return $fields;
    }

    /**
     * The tenant-wide fields only.
     *
     * @return list<TaskCustomField>
     */
    public function tenantFields(?string $tenantKey): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('f')->from(TaskCustomField::class, 'f')->where('f.board IS NULL');
        $tenantKey === null ? $query->andWhere('f.tenantKey IS NULL') : $query->andWhere('f.tenantKey = :tenant')->setParameter('tenant', $tenantKey);
        /** @var list<TaskCustomField> $fields */
        $fields = $query->getQuery()->getResult();
        $fields = array_values(array_filter($fields, static fn (TaskCustomField $field): bool => $field->getTenantKey() === $tenantKey));
        usort($fields, static fn (TaskCustomField $a, TaskCustomField $b): int => [$a->getPosition(), $a->getName()] <=> [$b->getPosition(), $b->getName()]);

        return $fields;
    }

    /** A field of the board (its own or its tenant's), null otherwise. */
    public function fieldOfBoard(TaskBoard $board, string $fieldId): ?TaskCustomField
    {
        foreach ($this->fieldsFor($board) as $field) {
            if ($field->getId() === $fieldId) {
                return $field;
            }
        }

        return null;
    }

    public function find(string $fieldId): ?TaskCustomField
    {
        if ($fieldId === '' || mb_strlen($fieldId) > 36) {
            return null;
        }
        $field = $this->entityManager->find(TaskCustomField::class, $fieldId);

        return $field instanceof TaskCustomField && $field->getId() === $fieldId ? $field : null;
    }

    /**
     * Defines a field for one board (`$board`) or for every board of a tenant (`$board` null, `$tenantKey`).
     *
     * @param list<array{id?: ?string, name: string, color?: ?string}> $options a dropdown's
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function define(object $actor, ?TaskBoard $board, ?string $tenantKey, string $name, CustomFieldType $type, array $options = [], ?int $position = null): TaskCustomField
    {
        $tenantKey = $board instanceof TaskBoard ? $board->getTenantKey() : $tenantKey;
        $this->requireRight($actor, $board, $tenantKey);
        try {
            $field = new TaskCustomField($name, $type, $tenantKey, $board, $position ?? $this->nextPosition($board, $tenantKey));
            $field->setOptions($options);
        } catch (InvalidArgumentException) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'definition']);
        }
        $this->write(function () use ($field): void {
            $this->entityManager->persist($field);
        });

        return $field;
    }

    /**
     * Renames, reorders or replaces the options of a field (the type stays). A removed option clears the values that held
     * it.
     *
     * @param list<array{id?: ?string, name: string, color?: ?string}>|null $options null keeps them
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function update(object $actor, TaskCustomField $field, string $name, ?array $options, int $position): TaskCustomField
    {
        $this->requireRight($actor, $field->getBoard(), $field->getTenantKey());
        $this->write(function () use ($field, $name, $options, $position): void {
            try {
                $field->setName($name)->setPosition($position);
                if ($options !== null) {
                    $field->setOptions($options);
                }
            } catch (InvalidArgumentException) {
                throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'definition']);
            }
            if ($field->getType() === CustomFieldType::Dropdown) {
                $ids = array_map(static fn (array $option): string => $option['id'], $field->getOptions());
                foreach ($this->entityManager->getRepository(TaskCustomFieldValue::class)->findBy(['field' => $field]) as $value) {
                    if (!in_array($value->getTextValue(), $ids, true)) {
                        $this->entityManager->remove($value);
                    }
                }
            }
        });

        return $field;
    }

    /**
     * Deletes the field and every value of it.
     *
     * @throws TaskCustomFieldRefusedException
     */
    public function delete(object $actor, TaskCustomField $field): void
    {
        $this->requireRight($actor, $field->getBoard(), $field->getTenantKey());
        $this->write(function () use ($field): void {
            foreach ($this->entityManager->getRepository(TaskCustomFieldValue::class)->findBy(['field' => $field]) as $value) {
                $this->entityManager->remove($value);
            }
            $this->entityManager->remove($field);
        });
    }

    /**
     * Sets a task's value (null or "" clears it): text (at most {@see self::TEXT_MAX} characters), a number (int, float or a
     * decimal string), or a dropdown option by id or exact name. Recorded in the task's history. The caller checked the
     * edit right on the task.
     *
     * @throws TaskCustomFieldRefusedException `not_found` (not a field of the task's board), `invalid` (`value`)
     */
    public function setField(Task $task, string $fieldId, mixed $value, object $actor): void
    {
        $field           = $this->fieldOfBoard($task->getBoard(), $fieldId) ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND);
        [$text, $number] = $this->normalize($field, $value);
        $this->write(function () use ($task, $field, $text, $number, $actor): void {
            $current = $this->entityManager->getRepository(TaskCustomFieldValue::class)->findOneBy(['task' => $task, 'field' => $field]);
            $before  = $current instanceof TaskCustomFieldValue ? $this->display($current) : null;
            if ($text === null && $number === null) {
                if ($current instanceof TaskCustomFieldValue) {
                    $this->entityManager->remove($current);
                }
                $after = null;
            } else {
                $current ??= new TaskCustomFieldValue($task, $field);
                $current->change($text, $number);
                $this->entityManager->persist($current);
                $after = $this->display($current);
            }
            $this->changeRecorder->record($task, $actor, TaskChangeType::CustomField, $before, $after, 'field:' . $field->getId() . ' ' . $field->getName());
        });
    }

    /** Since 2.17.0: whether any task holds a value of the field. */
    public function hasValues(TaskCustomField $field): bool
    {
        return $this->entityManager->getRepository(TaskCustomFieldValue::class)->count(['field' => $field]) > 0;
    }

    /**
     * The values of these tasks, by task id.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, list<TaskCustomFieldValue>>
     */
    public function valuesOf(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        /** @var list<TaskCustomFieldValue> $values */
        $values = $this->entityManager->createQueryBuilder()->select('v', 'f')->from(TaskCustomFieldValue::class, 'v')->join('v.field', 'f')
            ->where('IDENTITY(v.task) IN (:tasks)')->setParameter('tasks', $taskIds)->getQuery()->getResult();
        $byTask = [];
        foreach ($values as $value) {
            $byTask[$value->getTask()->getId()][] = $value;
        }

        return $byTask;
    }

    /** The value as people read it: the text, the number without trailing zeros, the option's name. */
    public function display(TaskCustomFieldValue $value): string
    {
        return match ($value->getField()->getType()) {
            CustomFieldType::Text     => (string) $value->getTextValue(),
            CustomFieldType::Number   => self::trimNumber((string) $value->getNumberValue()),
            CustomFieldType::Dropdown => (string) ($value->getField()->option((string) $value->getTextValue())['name'] ?? ''),
        };
    }

    /**
     * Narrows a task query (`$alias` the task) to the tasks every condition holds for. A condition on a field the board does
     * not have, or a malformed one, is refused, never ignored.
     *
     * @param list<TaskCustomFieldCondition> $conditions
     * @param array<string, TaskCustomField> $fields the fields the conditions may name, by id
     *
     * @throws TaskCustomFieldRefusedException `not_found`, `invalid` (`value`)
     */
    public function filter(QueryBuilder $qb, string $alias, array $conditions, array $fields): void
    {
        foreach (array_values($conditions) as $index => $condition) {
            $field = $fields[$condition->fieldId] ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_FOUND);
            $op    = $condition->operator;
            if ($op->needsValue() && $condition->values === []) {
                throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'value']);
            }
            if (($op === CustomFieldOperator::LessThan || $op === CustomFieldOperator::GreaterThan) && $field->getType() !== CustomFieldType::Number) {
                throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'operator']);
            }
            $v      = 'cfv' . $index;
            $column = $field->getType() === CustomFieldType::Number ? $v . '.numberValue' : $v . '.textValue';
            $sub    = 'SELECT ' . $v . '.id FROM ' . TaskCustomFieldValue::class . ' ' . $v . ' WHERE ' . $v . '.task = ' . $alias . ' AND IDENTITY(' . $v . '.field) = :cff' . $index;
            $qb->setParameter('cff' . $index, $field->getId());
            $values = array_map(fn (string $raw): string => $this->comparable($field, $raw), $condition->values);
            match ($op) {
                CustomFieldOperator::IsSet       => $qb->andWhere('EXISTS (' . $sub . ')'),
                CustomFieldOperator::IsNotSet    => $qb->andWhere('NOT EXISTS (' . $sub . ')'),
                CustomFieldOperator::Equals      => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' = :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0]),
                CustomFieldOperator::NotEquals   => $qb->andWhere('NOT EXISTS (' . $sub . ' AND ' . $column . ' = :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0]),
                CustomFieldOperator::LessThan    => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' < :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0]),
                CustomFieldOperator::GreaterThan => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' > :cfx' . $index . ')')->setParameter('cfx' . $index, $values[0]),
                CustomFieldOperator::In          => $qb->andWhere('EXISTS (' . $sub . ' AND ' . $column . ' IN (:cfx' . $index . '))')->setParameter('cfx' . $index, $values),
            };
        }
    }

    /**
     * @throws TaskCustomFieldRefusedException
     *
     * @return array{0: ?string, 1: ?string} text, number
     */
    private function normalize(TaskCustomField $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }
        $invalid = new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'value']);

        return match ($field->getType()) {
            CustomFieldType::Text     => is_string($value) && mb_strlen($value) <= self::TEXT_MAX ? [$value, null] : throw $invalid,
            CustomFieldType::Number   => [null, $this->number($value) ?? throw $invalid],
            CustomFieldType::Dropdown => [is_string($value) || is_int($value) ? ($this->optionId($field, (string) $value) ?? throw $invalid) : throw $invalid, null],
        };
    }

    /** The value a filter compares with: a decimal for a number, an option id for a dropdown (by id or name). */
    private function comparable(TaskCustomField $field, string $raw): string
    {
        return match ($field->getType()) {
            CustomFieldType::Number   => $this->number($raw) ?? throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::INVALID, ['field' => 'value']),
            CustomFieldType::Dropdown => $this->optionId($field, $raw) ?? '',
            CustomFieldType::Text     => $raw,
        };
    }

    private function number(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return is_finite($value) && abs($value) < 1e19 ? number_format($value, 10, '.', '') : null;
        }
        if (!is_string($value) || !is_numeric(trim($value)) || str_contains(strtolower($value), 'e') || abs((float) $value) >= 1e19) {
            return null;
        }

        return trim($value);
    }

    private function optionId(TaskCustomField $field, string $value): ?string
    {
        return $field->option($value)['id'] ?? $field->optionNamed($value)['id'] ?? null;
    }

    private static function trimNumber(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function nextPosition(?TaskBoard $board, ?string $tenantKey): int
    {
        $last = -1;
        foreach ($board instanceof TaskBoard ? $this->fieldsFor($board) : $this->tenantFields($tenantKey) as $field) {
            if ($field->getBoardId() === $board?->getId()) {
                $last = max($last, $field->getPosition());
            }
        }

        return $last + 1;
    }

    /**
     * @throws TaskCustomFieldRefusedException
     */
    private function requireRight(object $actor, ?TaskBoard $board, ?string $tenantKey): void
    {
        $allowed = $board instanceof TaskBoard ? $this->policy->mayRestructureBoard($actor, Snapshots::board($board)) : $this->policy->mayManageFolders($actor, $tenantKey);
        if (!$allowed) {
            throw new TaskCustomFieldRefusedException(TaskCustomFieldRefusedException::NOT_ALLOWED);
        }
    }

    /**
     * @param callable(): void $change
     */
    private function write(callable $change): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $change();
            RecoveringFlusher::flush($this->entityManager, $this->managerRegistry);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            RecoveringFlusher::resetIfClosed($this->entityManager, $this->managerRegistry);

            throw $failure;
        }
    }
}
