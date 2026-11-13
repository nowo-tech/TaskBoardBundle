<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use DateTimeImmutable;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

use function array_diff_key;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function sort;
use function strlen;
use function trim;

use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;

/**
 * Since 2.2.0 (spec 213): a write's JSON body. Each field an operation reads is marked; what nobody read is listed in the
 * answer's `warnings`, as `unknown`, or as `not_supported` for ClickUp fields Beacon has no equivalent for. A value of the
 * wrong type is a 400 naming the field, never a silent default.
 */
final class TaskApiInput
{
    /** @var array<string, true> */
    private array $read = [];

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $notSupported ClickUp fields this operation knows and ignores
     */
    public function __construct(private readonly array $fields, private readonly array $notSupported = [])
    {
    }

    /**
     * @param list<string> $notSupported
     *
     * @throws TaskApiRefusal
     */
    public static function fromRequest(Request $request, int $maxBytes, array $notSupported = []): self
    {
        $content = (string) $request->getContent();
        if (strlen($content) > $maxBytes) {
            throw new TaskApiRefusal(400, 'INPUT_001', 'Body too large');
        }
        if (trim($content) === '') {
            return new self([], $notSupported);
        }
        try {
            $decoded = json_decode($content, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new TaskApiRefusal(400, 'INPUT_001', 'Malformed JSON');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new TaskApiRefusal(400, 'INPUT_001', 'The body must be a JSON object');
        }

        /* @var array<string, mixed> $decoded */
        return new self($decoded, $notSupported);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    /** Whether the body sets any of these fields. */
    public function hasAny(string ...$names): bool
    {
        foreach ($names as $name) {
            if ($this->has($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws TaskApiRefusal
     */
    public function string(string $name, bool $required = false): ?string
    {
        $value = $this->take($name);
        if ($value === null) {
            return $required ? throw self::invalid($name) : null;
        }

        return is_string($value) ? $value : throw self::invalid($name);
    }

    /**
     * @throws TaskApiRefusal
     */
    public function bool(string $name): ?bool
    {
        $value = $this->take($name);

        return $value === null || is_bool($value) ? $value : throw self::invalid($name);
    }

    /**
     * An integer, given as a number or as a string of digits (ClickUp sends both).
     *
     * @throws TaskApiRefusal
     */
    public function int(string $name): ?int
    {
        $value = $this->take($name);
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,15}$/D', $value) === 1) {
            return (int) $value;
        }

        throw self::invalid($name);
    }

    /**
     * Unix milliseconds; null when absent or null (a date being cleared: ask {@see self::has()}).
     *
     * @throws TaskApiRefusal
     */
    public function date(string $name): ?DateTimeImmutable
    {
        $ms = $this->int($name);

        return $ms === null ? null : new DateTimeImmutable('@' . intdiv($ms, 1000));
    }

    /**
     * @throws TaskApiRefusal
     *
     * @return list<string>
     */
    public function strings(string $name): array
    {
        $value = $this->take($name) ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($name);
        }

        return array_map(static fn (mixed $item): string => is_string($item) || is_int($item) ? (string) $item : throw self::invalid($name), $value);
    }

    /**
     * Since 2.16.0: a list of JSON objects (each an array keyed by field name), e.g. the items of a bulk update.
     *
     * @throws TaskApiRefusal
     *
     * @return list<array<string, mixed>>
     */
    public function objects(string $name): array
    {
        $value = $this->take($name) ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($name);
        }
        foreach ($value as $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                throw self::invalid($name);
            }
        }

        /* @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * ClickUp's `{"add": [...], "rem": [...]}`.
     *
     * @throws TaskApiRefusal
     *
     * @return array{add: list<string>, rem: list<string>}
     */
    public function addRemove(string $name): array
    {
        $value = $this->take($name) ?? [];
        if (!is_array($value) || array_diff_key($value, ['add' => 0, 'rem' => 0]) !== []) {
            throw self::invalid($name);
        }
        $inner = new self(['add' => $value['add'] ?? [], 'rem' => $value['rem'] ?? []]);

        return ['add' => $inner->strings('add'), 'rem' => $inner->strings('rem')];
    }

    /**
     * Fields nobody read, sorted.
     *
     * @return list<array{field: string, reason: string}>
     */
    public function warnings(): array
    {
        $fields = array_keys(array_diff_key($this->fields, $this->read));
        sort($fields);

        return array_map(fn (string $field): array => ['field' => $field, 'reason' => in_array($field, $this->notSupported, true) ? 'not_supported' : 'unknown'], $fields);
    }

    private function take(string $name): mixed
    {
        // @igor-ignore - Per-request value object (TaskApiInput::fromRequest), never a shared service.
        $this->read[$name] = true;

        return $this->fields[$name] ?? null;
    }

    private static function invalid(string $name): TaskApiRefusal
    {
        return new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . $name);
    }
}
