<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Form\TaskCustomFieldFilterType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function explode;
use function is_numeric;
use function is_string;
use function trim;

/**
 * Since 2.19.0 (Beacon spec 234, FR-028): the list view's custom field columns and operator filters — one column per
 * native field of the board, and a filter (field, operator, value) read from the query string (`cf_field`, `cf_op`,
 * `cf_value`) that keeps only the tasks it holds for. Stateless.
 *
 * @internal used by the bundle's list page
 */
final readonly class TaskCustomFieldListView
{
    public function __construct(
        private TaskCustomFieldManager $fields,
        private FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * @param list<Task> $tasks
     *
     * @return array{tasks: list<Task>, customFields: list<TaskCustomField>, customValues: array<string, array<string, string>>, customFieldFilter: ?FormView}
     */
    public function prepare(Request $request, TaskBoard $board, array $tasks): array
    {
        $fields = $this->fields->fieldsFor($board);
        if ($fields === []) {
            return ['tasks' => $tasks, 'customFields' => [], 'customValues' => [], 'customFieldFilter' => null];
        }
        $values = $this->fields->valuesOf(array_map(static fn (Task $task): string => $task->getId(), $tasks));
        $form   = $this->formFactory->createNamed('', TaskCustomFieldFilterType::class, null, ['fields' => $fields]);
        $form->handleRequest($request);
        $condition = $this->condition($form, $fields);
        if ($condition !== null) {
            [$field, $operator, $wanted] = $condition;
            $tasks                       = array_values(array_filter($tasks, fn (Task $task): bool => $this->holds($field, $this->valueOf($values[$task->getId()] ?? [], $field), $operator, $wanted)));
        }
        $shown = [];
        foreach ($values as $taskId => $ofTask) {
            foreach ($ofTask as $value) {
                $shown[$taskId][$value->getField()->getId()] = $this->fields->display($value);
            }
        }

        return ['tasks' => $tasks, 'customFields' => $fields, 'customValues' => $shown, 'customFieldFilter' => $form->createView()];
    }

    /**
     * Whether the value (null: not set) meets the operator: text and dropdown compare exactly (a dropdown by option id or
     * name), numbers as decimals.
     *
     * @param list<string> $wanted
     */
    public function holds(TaskCustomField $field, ?TaskCustomFieldValue $value, CustomFieldOperator $operator, array $wanted): bool
    {
        $current = match (true) {
            !$value instanceof TaskCustomFieldValue       => null,
            $field->getType() === CustomFieldType::Number => (string) $value->getNumberValue(),
            default                                       => (string) $value->getTextValue(),
        };
        $wanted = array_map(static fn (string $one): string => $field->getType() === CustomFieldType::Dropdown ? $field->option($one)['id'] ?? $field->optionNamed($one)['id'] ?? '' : trim($one), $wanted);
        $equals = static fn (string $one): bool => $current !== null && ($field->getType() === CustomFieldType::Number ? is_numeric($one) && (float) $current === (float) $one : $current === $one);

        return match ($operator) {
            CustomFieldOperator::IsSet       => $current !== null,
            CustomFieldOperator::IsNotSet    => $current === null,
            CustomFieldOperator::Equals      => $equals($wanted[0] ?? ''),
            CustomFieldOperator::NotEquals   => !$equals($wanted[0] ?? ''),
            CustomFieldOperator::In          => array_filter($wanted, $equals) !== [],
            CustomFieldOperator::LessThan    => $current !== null && is_numeric($wanted[0] ?? '') && (float) $current < (float) $wanted[0],
            CustomFieldOperator::GreaterThan => $current !== null && is_numeric($wanted[0] ?? '') && (float) $current > (float) $wanted[0],
        };
    }

    /**
     * @param FormInterface<mixed> $form
     * @param list<TaskCustomField> $fields
     *
     * @return array{0: TaskCustomField, 1: CustomFieldOperator, 2: list<string>}|null
     */
    private function condition(FormInterface $form, array $fields): ?array
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return null;
        }
        $fieldId  = $form->get('cf_field')->getData();
        $operator = $form->get('cf_op')->getData();
        $raw      = $form->get('cf_value')->getData();
        foreach ($fields as $field) {
            if ($field->getId() === $fieldId && $operator instanceof CustomFieldOperator) {
                $wanted = is_string($raw) ? array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $one): bool => $one !== '')) : [];
                if ($operator->needsValue() && $wanted === []) {
                    return null;
                }

                return [$field, $operator, $operator === CustomFieldOperator::In ? $wanted : array_slice($wanted, 0, 1)];
            }
        }

        return null;
    }

    /**
     * @param list<TaskCustomFieldValue> $values
     */
    private function valueOf(array $values, TaskCustomField $field): ?TaskCustomFieldValue
    {
        foreach ($values as $value) {
            if ($value->getField()->getId() === $field->getId()) {
                return $value;
            }
        }

        return null;
    }
}
