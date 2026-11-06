<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;

use function in_array;

/**
 * Since 1.27.0 (TB-SYNC-3): one custom field of a provider task — its board-level definition and the task's value,
 * already validated by the adapter (untrusted input: types checked, text sanitised and cut, URLs http(s) only).
 *
 * `value` by type: text, url, email, phone, date (ISO 8601) and number / money (a decimal string) are strings;
 * checkbox a bool; rating and progress an int; dropdown a choice id; labels a list of choice ids; people and
 * relationship a list of `{id, name}`. Null = no value. `text` is the value as one line (search, fallback display).
 */
final readonly class RemoteCustomField
{
    /**
     * @param list<array{id: string, name: string, color: string|null}> $choices dropdown / labels options
     * @param array<string, int|string> $config `currency` (money, ISO 4217), `max` (rating), `time` (date with time: 1)
     */
    public function __construct(
        public string $id,
        public string $name,
        public SyncFieldType $type,
        public string $remoteType,
        public array $choices = [],
        public array $config = [],
        public mixed $value = null,
        public string $text = '',
        /** Since 1.27.0 (audit GW-L1): `value` did not fit `type` and is only text; the definition keeps the declared type. */
        public bool $fallback = false,
    ) {
    }

    public function hasValue(): bool
    {
        return !in_array($this->value, [null, '', []], true);
    }
}
