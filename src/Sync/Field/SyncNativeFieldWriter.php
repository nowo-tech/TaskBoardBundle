<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskCustomField;
use Nowo\TaskBoardBundle\Entity\TaskCustomFieldValue;
use Nowo\TaskBoardBundle\Enum\CustomFieldType;
use Nowo\TaskBoardBundle\Exception\TaskChangedException;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldRefusedException;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\SyncRunContext;

use function hash;
use function is_scalar;
use function json_encode;
use function rtrim;
use function str_contains;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 3.3.0 (CU-3, design §11): writes the provider custom fields an import plan landed on **native** fields
 * ({@see TaskBoardConnection::nativeFields()}): ClickUp `text` / `short_text` → text, `number` → number, `drop_down` →
 * dropdown. Every other type stays a synced, read-only field ({@see self::handles()} is false for it).
 *
 * - The target is the board's own field of the provenance marker `<provider>:<remote field id>` (created on first use,
 *   found again by that marker on every re-import, whatever its name) or a native field the plan chose.
 * - Dropdown options are matched by the provider's option id ({@see TaskCustomFieldManager::adoptSourceOptions()}); an
 *   option deleted here is never recreated: the value is a conflict note.
 * - **Re-import rule** (no overwrite mode): the provider's value is written only while nobody changed the task's value
 *   here since the last import. A value set here (also one that existed before the first import) is kept and the
 *   provider's value is noted as a conflict once. An empty provider value never clears a value here.
 * - Each write goes through {@see TaskCustomFieldManager::setFieldUnlessChanged()} (task row locked, value read again):
 *   a person's edit that lands between the check and the write wins (REQ-RUNTIME-003).
 *
 * Per run, only ids and option maps are remembered (in the run's context): the rows are read again on every page.
 * Stateless otherwise (FrankenPHP worker mode).
 */
final readonly class SyncNativeFieldWriter
{
    /** Provider field types (`RemoteCustomField::$remoteType`) a native field can take. */
    public const NATIVE_TYPES = [
        'text'       => CustomFieldType::Text,
        'short_text' => CustomFieldType::Text,
        'number'     => CustomFieldType::Number,
        'drop_down'  => CustomFieldType::Dropdown,
    ];

    /** The baseline key prefix of a native value on the task's sync item. */
    public const BASELINE_PREFIX = 'native:';

    public function __construct(
        private TaskCustomFieldManager $fields,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** The native type a provider field type can land on, null when it stays synced. */
    public static function nativeType(string $remoteType): ?CustomFieldType
    {
        return self::NATIVE_TYPES[$remoteType] ?? null;
    }

    /** Whether the connection lands this provider field on a native field (the synced writer then leaves it out). */
    public static function handles(TaskBoardConnection $connection, RemoteCustomField $remote): bool
    {
        return isset($connection->nativeFields()[$remote->id]) && self::nativeType($remote->remoteType) instanceof CustomFieldType;
    }

    /**
     * Writes the task's native values of this connection.
     *
     * @param list<RemoteCustomField> $remoteFields
     *
     * @return array<string, string> the values not applied (conflicts): field name => the provider's value
     */
    public function write(SyncRunContext $ctx, TaskSyncItem $item, Task $task, array $remoteFields): array
    {
        $targets   = $ctx->connection->nativeFields();
        $conflicts = [];
        foreach ($remoteFields as $remote) {
            $type = self::nativeType($remote->remoteType);
            if (!isset($targets[$remote->id]) || !$type instanceof CustomFieldType) {
                continue;
            }
            $field = $this->target($ctx, $remote, $type, $targets[$remote->id]);
            if (!$field instanceof TaskCustomField || !$remote->hasValue()) {
                continue;
            }
            if ($remote->fallback) {
                // A value that did not fit the provider's own type is not written to a typed field.
                $ctx->count('fields.native_misfit');
                continue;
            }
            [$value, $preview] = $this->value($ctx, $field, $remote);
            if ($value === null) {
                // A dropdown option deleted here (or one the field had no room for): never recreated.
                $conflicts[$field->getName()] = $preview;
                $ctx->count('fields.native_conflicts');
                continue;
            }
            $conflict = $this->apply($ctx, $item, $task, $field, $remote, $value);
            if ($conflict) {
                $conflicts[$field->getName()] = $preview;
            }
        }

        return $conflicts;
    }

    /**
     * The task's field value under the re-import rule; true when the provider's value was not applied (a conflict to note).
     */
    private function apply(SyncRunContext $ctx, TaskSyncItem $item, Task $task, TaskCustomField $field, RemoteCustomField $remote, string $value): bool
    {
        $key        = self::BASELINE_PREFIX . $field->getId();
        $remoteHash = $this->hash([$remote->remoteType, $value]);
        $current    = $this->current($task, $field);
        $localHash  = $this->hash($current);
        $baseline   = $item->baseline($key);
        $expected   = $baseline[1] ?? $this->hash(null);
        if ($baseline !== null && $remoteHash === $baseline[0]) {
            return false;
        }
        if ($localHash === $expected) {
            try {
                // @igor-ignore - A call on the stateless field manager (it writes a database row), not a change of the service.
                $this->fields->setFieldUnlessChanged($task, $field->getId(), $value, $ctx->actor, fn (?TaskCustomFieldValue $stored): bool => $this->hash($this->canonical($stored)) === $localHash);
            } catch (TaskChangedException) {
                // Changed here while this run wrote it: the edit here wins (REQ-RUNTIME-003). Only a second process can
                // change the value between the read above and the locked re-read (CONCURRENCY.md).
                return $this->conflict($ctx, $item, $key, $remoteHash); // @codeCoverageIgnore
            } catch (TaskCustomFieldRefusedException) {
                $ctx->count('fields.native_invalid');

                return false;
            }
            // @igor-ignore - A Doctrine entity of this sync page, not a shared service.
            $item->setBaseline($key, $remoteHash, $this->hash($this->current($task, $field)))->setConflictHash($key, null);
            $ctx->count('fields.native_values');

            return false;
        }
        if ($localHash === $this->hash($this->canonicalOf($field, $value))) {
            // Same value on both sides: nothing to write, the baseline follows.
            // @igor-ignore - A Doctrine entity of this sync page, not a shared service.
            $item->setBaseline($key, $remoteHash, $localHash)->setConflictHash($key, null);

            return false;
        }

        return $this->conflict($ctx, $item, $key, $remoteHash);
    }

    /** Notes a conflict once per provider value (the same value is not noted again on the next re-import). */
    private function conflict(SyncRunContext $ctx, TaskSyncItem $item, string $key, string $remoteHash): bool
    {
        if ($item->conflictHash($key) === $remoteHash) {
            return false;
        }
        // @igor-ignore - A Doctrine entity of this sync page, not a shared service.
        $item->setConflictHash($key, $remoteHash);
        $ctx->count('fields.native_conflicts');

        return true;
    }

    /**
     * The value to store and its preview: the text (cut to the field's limit), the number, or this field's option id.
     *
     * @return array{0: string|null, 1: string}
     */
    private function value(SyncRunContext $ctx, TaskCustomField $field, RemoteCustomField $remote): array
    {
        $raw = is_scalar($remote->value) ? (string) $remote->value : $remote->text;
        if ($field->getType() !== CustomFieldType::Dropdown) {
            $value = $field->getType() === CustomFieldType::Text ? Text::cut($raw, TaskCustomFieldManager::TEXT_MAX) : $raw;

            return [$value, Text::cut($remote->text !== '' ? $remote->text : $raw, 200)];
        }
        $preview = $raw;
        foreach ($remote->choices as $choice) {
            if ($choice['id'] === $raw) {
                $preview = $choice['name'];
            }
        }
        $options = $ctx->nativeFields[$remote->id]['options'] ?? [];

        return [$options[$raw] ?? null, Text::cut($preview, 200)];
    }

    /**
     * The native field of a provider field for this run: resolved once per run (ids and the option map only), then read
     * by id on each page. Null when it cannot be used (warned once).
     */
    private function target(SyncRunContext $ctx, RemoteCustomField $remote, CustomFieldType $type, string $target): ?TaskCustomField
    {
        $known = $ctx->nativeFields[$remote->id] ?? null;
        if ($known !== null) {
            return $known['id'] !== null ? $this->fields->fieldOfBoard($ctx->board, $known['id']) : null;
        }
        $field = null;
        if ($target === TaskBoardConnection::NATIVE_CREATE) {
            try {
                $field = $this->fields->defineFromSource($ctx->actor, $ctx->board, $ctx->provider->id() . ':' . $remote->id, $remote->name, $type);
                if (!$field instanceof TaskCustomField) {
                    $ctx->warn('native_field_unavailable');
                }
            } catch (TaskCustomFieldRefusedException) {
                $ctx->warn('native_field_refused');
            }
        } else {
            $field = $this->fields->fieldOfBoard($ctx->board, $target);
            if (!$field instanceof TaskCustomField || $field->getType() !== $type) {
                $field = null;
                $ctx->warn('native_field_unavailable');
            }
        }
        $options = $field instanceof TaskCustomField && $type === CustomFieldType::Dropdown ? $this->fields->adoptSourceOptions($field, $remote->choices) : [];
        // @igor-ignore - Built per sync run, not a shared service.
        $ctx->nativeFields[$remote->id] = ['id' => $field?->getId(), 'options' => $options];
        if ($field instanceof TaskCustomField && $target === TaskBoardConnection::NATIVE_CREATE) {
            $ctx->count('fields.native_fields');
        }

        return $field;
    }

    /** The task's stored value in comparable form (null: none). */
    private function current(Task $task, TaskCustomField $field): ?string
    {
        $value = $this->entityManager->getRepository(TaskCustomFieldValue::class)->findOneBy(['task' => $task->getId(), 'field' => $field->getId()]);
        if ($value instanceof TaskCustomFieldValue) {
            $this->entityManager->refresh($value);
        }

        return $this->canonical($value instanceof TaskCustomFieldValue ? $value : null);
    }

    private function canonical(?TaskCustomFieldValue $value): ?string
    {
        if (!$value instanceof TaskCustomFieldValue) {
            return null;
        }

        return $value->getField()->getType() === CustomFieldType::Number ? $this->number((string) $value->getNumberValue()) : $value->getTextValue();
    }

    private function canonicalOf(TaskCustomField $field, string $value): string
    {
        return $field->getType() === CustomFieldType::Number ? $this->number($value) : $value;
    }

    /** A decimal without trailing zeros ("12.5000000000" and "12.50" are the same number). */
    private function number(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
