<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Symfony\Component\Form\FormInterface;

use function is_float;
use function is_int;
use function is_string;
use function str_replace;

/**
 * Since 2.19.0 (Beacon spec 234, FR-028): the task form's custom field rows — the `custom_fields` option of
 * `TaskFormType` for a task (its board's fields and its values) and the save of what was posted. Stateless.
 *
 * @internal used by the bundle's task pages
 */
final readonly class TaskCustomFieldForm
{
    public const PREFIX = 'cf_';

    public function __construct(
        private TaskCustomFieldManager $fields,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, name_attr: string, type: string, value: float|string|null, choices: array<string, string>}>
     */
    public function options(TaskBoard $board, ?Task $task = null): array
    {
        $values = [];
        if ($task instanceof Task) {
            foreach ($this->fields->valuesOf([$task->getId()])[$task->getId()] ?? [] as $value) {
                $values[$value->getField()->getId()] = $value;
            }
        }
        $rows = [];
        foreach ($this->fields->fieldsFor($board) as $field) {
            $choices = [];
            foreach ($field->getOptions() as $option) {
                $choices[$option['name']] = $option['id'];
            }
            $rows[] = [
                'id'        => $field->getId(),
                'name'      => $field->getName(),
                'name_attr' => self::nameOf($field),
                'type'      => $field->getType()->value,
                'value'     => isset($values[$field->getId()]) ? $this->formValue($values[$field->getId()]) : null,
                'choices'   => $choices,
            ];
        }

        return $rows;
    }

    /**
     * Writes the posted rows that changed. A refused value (malformed) is skipped and reported.
     *
     * @param FormInterface<mixed> $form
     *
     * @return list<string> the names of the fields whose value was refused
     */
    public function save(FormInterface $form, Task $task, object $actor): array
    {
        $refused = [];
        foreach ($this->options($task->getBoard(), $task) as $row) {
            $posted = $form->has($row['name_attr']) ? $form->get($row['name_attr'])->getData() : $row['value'];
            $posted = is_float($posted) || is_int($posted) ? (string) $posted : $posted;
            $before = is_float($row['value']) ? (string) $row['value'] : $row['value'];
            if ($posted === $before || (($posted === null || $posted === '') && $before === null)) {
                continue;
            }
            try {
                // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
                $this->fields->setField($task, $row['id'], is_string($posted) ? $posted : null, $actor);
            } catch (TaskCustomFieldRefusedException) {
                $refused[] = $row['name'];
            }
        }

        return $refused;
    }

    public static function nameOf(TaskCustomField $field): string
    {
        return self::PREFIX . str_replace('-', '_', $field->getId());
    }

    private function formValue(TaskCustomFieldValue $value): string|float|null
    {
        return match ($value->getField()->getType()) {
            CustomFieldType::Number => (float) $value->getNumberValue(),
            default                 => $value->getTextValue(),
        };
    }
}
