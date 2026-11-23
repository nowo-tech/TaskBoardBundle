<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Symfony\Component\Form\FormInterface;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function str_replace;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 2.19.0 (Beacon spec 234, FR-028): the task form's custom field rows — the `custom_fields` option of
 * `TaskFormType` for a task (its board's fields and its values) and the save of what was posted. Stateless.
 *
 * @internal used by the bundle's task pages
 */
final readonly class TaskCustomFieldForm
{
    public const PREFIX = 'cf_';

    /** Since 2.31.0: the hidden, unmapped field of the edit form with the values it showed ({@see self::encodeBase()}). */
    public const BASE_FIELD = 'customFieldBase';

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
     * Writes the posted rows that changed. A refused value (malformed) is skipped and reported. Since 2.31.0, with the
     * values the edit form showed ({@see self::BASE_FIELD}): only the rows the person changed are written, and a row
     * whose stored value someone else changed meanwhile is skipped and reported in `$changed`.
     *
     * @param FormInterface<mixed> $form
     * @param list<string>|null $changed set to the names of the fields not saved because they changed meanwhile
     *
     * @param-out list<string> $changed
     *
     * @return list<string> the names of the fields whose value was refused
     */
    public function save(FormInterface $form, Task $task, object $actor, ?array &$changed = null): array
    {
        $base    = $this->postedBase($form);
        $refused = [];
        $changed = [];
        foreach ($this->options($task->getBoard(), $task) as $row) {
            $posted = $form->has($row['name_attr']) ? $form->get($row['name_attr'])->getData() : $row['value'];
            $posted = is_float($posted) || is_int($posted) ? (string) $posted : $posted;
            $value  = is_string($posted) ? $posted : null;
            try {
                if ($base === null) {
                    $before = is_float($row['value']) ? (string) $row['value'] : $row['value'];
                    if ($posted === $before || (($posted === null || $posted === '') && $before === null)) {
                        continue;
                    }
                    // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
                    $this->fields->setField($task, $row['id'], $value, $actor);

                    continue;
                }
                // Since 2.31.0 (REQ-RUNTIME-003 rule 4): only what the person changed is written, and only while the stored
                // value is still the one the form showed (read under the task's row lock).
                $shown = self::norm($base[$row['id']] ?? $row['value']);
                if (self::norm($value) === $shown) {
                    continue;
                }
                // @igor-ignore - Call on a stateless readonly service (persists through Doctrine); no state kept in the worker.
                $this->fields->setFieldUnlessChanged($task, $row['id'], $value, $actor, fn (?TaskCustomFieldValue $current): bool => self::norm($current instanceof TaskCustomFieldValue ? $this->formValue($current) : null) === $shown);
            } catch (TaskCustomFieldRefusedException) {
                $refused[] = $row['name'];
            } catch (TaskChangedException) {
                $changed[] = $row['name'];
            }
        }

        return $refused;
    }

    /**
     * Since 2.31.0 (REQ-RUNTIME-003 rule 4): refuses, before anything is saved, an edit form that changes a custom field
     * someone else changed since the form was built (the stored value is no longer the one the form showed). A form
     * without the shown values (a host form) is not checked.
     *
     * @param FormInterface<mixed> $form
     *
     * @throws TaskChangedException
     */
    public function assertUnchanged(FormInterface $form, Task $task): void
    {
        $base = $this->postedBase($form);
        if ($base === null) {
            return;
        }
        foreach ($this->options($task->getBoard(), $task) as $row) {
            if (!$form->has($row['name_attr']) || !isset($base[$row['id']])) {
                continue;
            }
            $posted  = $form->get($row['name_attr'])->getData();
            $posted  = self::norm(is_float($posted) || is_int($posted) ? (string) $posted : (is_string($posted) ? $posted : null));
            $shown   = self::norm($base[$row['id']]);
            $current = self::norm($row['value']);
            // The person changed it, someone else changed it too, and not to the same value.
            if ($posted !== $shown && $current !== $shown && $posted !== $current) {
                throw new TaskChangedException($task->getRevision());
            }
        }
    }

    /**
     * Since 2.31.0: the values a form shows (`custom_fields` rows), as the hidden field carries them (JSON id => text).
     *
     * @param list<array{id: string, value: float|string|null}> $rows
     */
    public static function encodeBase(array $rows): string
    {
        $base = [];
        foreach ($rows as $row) {
            $base[$row['id']] = self::norm($row['value']);
        }

        return json_encode($base, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The shown values posted with the form; null without the field or with anything but a JSON map of strings.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, string>|null
     */
    private function postedBase(FormInterface $form): ?array
    {
        $raw = $form->has(self::BASE_FIELD) ? $form->get(self::BASE_FIELD)->getData() : null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $base = [];
        foreach ($decoded as $id => $value) {
            if (!is_string($value)) {
                return null;
            }
            $base[(string) $id] = $value;
        }

        return $base;
    }

    /** One spelling of a value for comparisons: empty for none, the number as PHP writes it. */
    private static function norm(float|int|string|null $value): string
    {
        return $value === null ? '' : (string) $value;
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
