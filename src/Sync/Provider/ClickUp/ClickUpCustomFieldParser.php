<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider\ClickUp;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;

use function array_slice;
use function array_values;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;
use function strtoupper;

use const DATE_ATOM;
use const FILTER_VALIDATE_EMAIL;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.27.0 (TB-SYNC-3): ClickUp's `custom_fields` of a task row as typed {@see RemoteCustomField}s. Remote data is
 * untrusted: every value is type-checked, text is sanitised and cut, URLs must be http(s), e-mails valid, numbers
 * finite. A value that does not fit its type, and every type the bundle does not know, is kept as text.
 */
final readonly class ClickUpCustomFieldParser
{
    public const MAX_FIELDS = 100;

    public const MAX_CHOICES = 200;

    public const MAX_TEXT = 2000;

    private const ID_PATTERN = '/^[A-Za-z0-9_:.-]{1,128}$/D';

    private const TYPES = [
        'text'               => SyncFieldType::Text,
        'short_text'         => SyncFieldType::Text,
        'number'             => SyncFieldType::Number,
        'currency'           => SyncFieldType::Money,
        'date'               => SyncFieldType::Date,
        'drop_down'          => SyncFieldType::Dropdown,
        'labels'             => SyncFieldType::Labels,
        'checkbox'           => SyncFieldType::Checkbox,
        'url'                => SyncFieldType::Url,
        'email'              => SyncFieldType::Email,
        'phone'              => SyncFieldType::Phone,
        'emoji'              => SyncFieldType::Rating,
        'automatic_progress' => SyncFieldType::Progress,
        'manual_progress'    => SyncFieldType::Progress,
        'users'              => SyncFieldType::People,
        'tasks'              => SyncFieldType::Relationship,
        'list_relationship'  => SyncFieldType::Relationship,
    ];

    public function __construct(private RemoteContentSanitizer $sanitizer = new RemoteContentSanitizer())
    {
    }

    /**
     * @param mixed $fields the row's `custom_fields`
     *
     * @return list<RemoteCustomField>
     */
    public function parse(mixed $fields): array
    {
        $parsed = [];
        foreach (is_array($fields) ? array_slice($fields, 0, self::MAX_FIELDS) : [] as $field) {
            $field = is_array($field) ? $this->field($field) : null;
            if ($field instanceof RemoteCustomField) {
                $parsed[] = $field;
            }
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $field
     */
    private function field(array $field): ?RemoteCustomField
    {
        $id   = is_scalar($field['id'] ?? null) ? (string) $field['id'] : '';
        $name = $this->sanitizer->line(is_string($field['name'] ?? null) ? $field['name'] : '', 120);
        if (preg_match(self::ID_PATTERN, $id) !== 1 || $name === '') {
            return null;
        }
        $remoteType = is_string($field['type'] ?? null) ? strtolower($this->sanitizer->line($field['type'], 32)) : '';
        $type       = self::TYPES[$remoteType] ?? SyncFieldType::Text;
        $config     = is_array($field['type_config'] ?? null) ? $field['type_config'] : [];
        $choices    = in_array($type, [SyncFieldType::Dropdown, SyncFieldType::Labels], true) ? $this->choices($config) : [];
        $settings   = $this->config($type, $config);
        $value      = $field['value'] ?? null;
        if (in_array($value, [null, '', []], true)) {
            return new RemoteCustomField($id, $name, $type, $remoteType, $choices, $settings);
        }

        [$typed, $text] = $this->value($type, $value, $choices, $settings) ?? [null, ''];
        if ($typed === null) {
            // A value that does not fit its type: kept as text, never dropped.
            $text = $this->asText($value);

            return new RemoteCustomField($id, $name, SyncFieldType::Text, $remoteType, [], [], $text === '' ? null : $text, $text);
        }

        return new RemoteCustomField($id, $name, $type, $remoteType, $choices, $settings, $typed, $text);
    }

    /**
     * The typed value and its one-line text, or null when it does not fit the type.
     *
     * @param list<array{id: string, name: string, color: string|null}> $choices
     * @param array<string, int|string> $config
     *
     * @return array{0: mixed, 1: string}|null
     */
    private function value(SyncFieldType $type, mixed $value, array $choices, array $config): ?array
    {
        return match ($type) {
            SyncFieldType::Text                                => is_scalar($value) && !is_bool($value) ? $this->textValue((string) $value) : null,
            SyncFieldType::Number                              => $this->number($value),
            SyncFieldType::Money                               => ($n = $this->number($value)) === null ? null : [$n[0], rtrim($n[1] . ' ' . ($config['currency'] ?? ''))],
            SyncFieldType::Date                                => $this->date($value, ($config['time'] ?? 0) === 1),
            SyncFieldType::Dropdown                            => $this->dropdown($value, $choices),
            SyncFieldType::Labels                              => $this->labels($value, $choices),
            SyncFieldType::Checkbox                            => $this->checkbox($value),
            SyncFieldType::Url                                 => is_string($value) && $this->sanitizer->isSafeUrl($value) && preg_match('~^https?://~i', $value) === 1 && strlen($value) <= 2048 ? [$value, $value] : null,
            SyncFieldType::Email                               => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? [$value, $value] : null,
            SyncFieldType::Phone                               => is_scalar($value) && preg_match('/^\+?[0-9 ().-]{3,40}$/D', (string) $value) === 1 ? [(string) $value, (string) $value] : null,
            SyncFieldType::Rating                              => is_numeric($value) && (int) $value >= 0 ? [min((int) $value, (int) ($config['max'] ?? 5)), sprintf('%d/%d', min((int) $value, (int) ($config['max'] ?? 5)), (int) ($config['max'] ?? 5))] : null,
            SyncFieldType::Progress                            => $this->progress($value),
            SyncFieldType::People, SyncFieldType::Relationship => $this->references($value),
        };
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function textValue(string $value): ?array
    {
        $text = $this->sanitizer->line($value, self::MAX_TEXT);

        return $text === '' ? null : [$text, $text];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function number(mixed $value): ?array
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || strlen((string) $value) > 40) {
            return null;
        }
        $number = (string) (0 + $value);
        if (str_contains(strtolower($number), 'inf') || str_contains(strtolower($number), 'nan')) {
            return null;
        }

        return [$number, $number];
    }

    /**
     * ClickUp dates are milliseconds since the epoch, in UTC.
     *
     * @return array{0: string, 1: string}|null
     */
    private function date(mixed $value, bool $withTime): ?array
    {
        if (!is_numeric($value) || strlen((string) $value) > 16) {
            return null;
        }
        $seconds = (int) ((int) $value / 1000);
        $date    = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));

        return [$date->format(DATE_ATOM), $date->format($withTime ? 'Y-m-d H:i' : 'Y-m-d')];
    }

    /**
     * A drop-down value is the option's `orderindex` (or, in some answers, its id).
     *
     * @param list<array{id: string, name: string, color: string|null}> $choices
     *
     * @return array{0: string, 1: string}|null
     */
    private function dropdown(mixed $value, array $choices): ?array
    {
        if (!is_scalar($value)) {
            return null;
        }
        foreach ($choices as $index => $choice) {
            if ($choice['id'] === (string) $value || (is_numeric($value) && (int) $value === $index)) {
                return [$choice['id'], $choice['name']];
            }
        }

        return null;
    }

    /**
     * @param list<array{id: string, name: string, color: string|null}> $choices
     *
     * @return array{0: list<string>, 1: string}|null
     */
    private function labels(mixed $value, array $choices): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $ids   = [];
        $names = [];
        foreach ($choices as $choice) {
            if (in_array($choice['id'], $value, true)) {
                $ids[]   = $choice['id'];
                $names[] = $choice['name'];
            }
        }

        return $ids === [] ? null : [$ids, implode(', ', $names)];
    }

    /**
     * @return array{0: bool, 1: string}|null
     */
    private function checkbox(mixed $value): ?array
    {
        $bool = is_bool($value) ? $value : ($value === 'true' ? true : ($value === 'false' ? false : null));

        return $bool === null ? null : [$bool, ''];
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function progress(mixed $value): ?array
    {
        $percent = is_array($value) ? ($value['percent_completed'] ?? $value['percent_complete'] ?? null) : $value;
        if (!is_numeric($percent)) {
            return null;
        }
        $percent = max(0, min(100, (int) $percent));

        return [$percent, $percent . '%'];
    }

    /**
     * Users or tasks: `[{id, name|username}]`, each id once, at most {@see self::MAX_CHOICES}.
     *
     * @return array{0: list<array{id: string, name: string}>, 1: string}|null
     */
    private function references(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $refs = [];
        foreach (array_slice($value, 0, self::MAX_CHOICES) as $item) {
            $id   = is_array($item) && is_scalar($item['id'] ?? null) ? (string) $item['id'] : '';
            $name = is_array($item) ? $this->sanitizer->line(is_string($item['name'] ?? null) ? $item['name'] : (is_string($item['username'] ?? null) ? $item['username'] : ''), 120) : '';
            if (preg_match(self::ID_PATTERN, $id) === 1) {
                $refs[$id] ??= ['id' => $id, 'name' => $name !== '' ? $name : $id];
            }
        }
        $refs  = array_values($refs);
        $names = [];
        foreach ($refs as $ref) {
            $names[] = $ref['name'];
        }

        return $refs === [] ? null : [$refs, implode(', ', $names)];
    }

    /**
     * Drop-down / labels options: `{id, name|label, color}`, in their order.
     *
     * @param array<mixed> $config
     *
     * @return list<array{id: string, name: string, color: string|null}>
     */
    private function choices(array $config): array
    {
        $choices = [];
        foreach (is_array($config['options'] ?? null) ? array_slice($config['options'], 0, self::MAX_CHOICES) : [] as $option) {
            $id   = is_array($option) && is_scalar($option['id'] ?? null) ? (string) $option['id'] : '';
            $name = is_array($option) ? $this->sanitizer->line(is_string($option['name'] ?? null) ? $option['name'] : (is_string($option['label'] ?? null) ? $option['label'] : ''), 120) : '';
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }
            $color     = is_array($option) && is_string($option['color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{3,8}$/D', $option['color']) === 1 ? $option['color'] : null;
            $choices[] = ['id' => $id, 'name' => $name !== '' ? $name : $id, 'color' => $color];
        }

        return $choices;
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, int|string>
     */
    private function config(SyncFieldType $type, array $config): array
    {
        return match ($type) {
            SyncFieldType::Money  => is_string($config['currency_type'] ?? null) && preg_match('/^[A-Za-z]{3}$/D', $config['currency_type']) === 1 ? ['currency' => strtoupper($config['currency_type'])] : [],
            SyncFieldType::Rating => ['max' => is_numeric($config['count'] ?? null) ? max(1, min(10, (int) $config['count'])) : 5],
            SyncFieldType::Date   => ($config['time'] ?? null) === true ? ['time' => 1] : [],
            default               => [],
        };
    }

    /** Any value as one sanitised line (scalars as they are, structures as compact JSON). */
    private function asText(mixed $value): string
    {
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->sanitizer->line($text, 500);
    }
}
