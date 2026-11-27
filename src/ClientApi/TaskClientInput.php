<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientSource;
use Symfony\Component\HttpFoundation\Request;

use function array_diff;
use function array_is_list;
use function array_keys;
use function count;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Since 3.4.0 (client API v1): reading a request body strictly — a JSON object of at most `client_api.max_body_bytes`,
 * only the fields a route lists (anything else is `400 invalid_field`, no silent warnings for a first-party client), each
 * of the expected type. Ids are 1–64 characters `[A-Za-z0-9_-]`; dates RFC 3339 with an offset. Pure functions.
 *
 * @internal
 */
final class TaskClientInput
{
    /** Longest task description (characters), as the Task API. */
    public const DESCRIPTION_MAX = 100_000;

    private const ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * The JSON object of the request (an empty body is an empty object).
     *
     * @throws TaskClientApiException
     *
     * @return array<string, mixed>
     */
    public static function json(Request $request, int $maxBytes): array
    {
        $content = (string) $request->getContent();
        if (strlen($content) > $maxBytes) {
            throw new TaskClientApiException('invalid_json', 413);
        }
        if (trim($content) === '') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TaskClientApiException('invalid_json', 400);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new TaskClientApiException('invalid_json', 400);
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $allowed
     *
     * @throws TaskClientApiException the first field not allowed
     */
    public static function only(array $body, array $allowed): void
    {
        foreach (array_diff(array_keys($body), $allowed) as $field) {
            throw TaskClientApiException::invalidField((string) $field);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public static function id(array $body, string $field, bool $required = false): ?string
    {
        $value = $body[$field] ?? null;
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || preg_match(self::ID, $value) !== 1) {
            throw TaskClientApiException::invalidField($field);
        }

        return $value;
    }

    /** Whether an id from a path or a query is well formed (else the answer is `404 not_found`). */
    public static function isId(?string $value): bool
    {
        return $value !== null && preg_match(self::ID, $value) === 1;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public static function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value)) {
            throw TaskClientApiException::invalidField($field);
        }

        return $value;
    }

    /**
     * A string or null; an empty string is null.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public static function nullableString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw TaskClientApiException::invalidField($field);
        }

        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * A list of at most `$max` strings.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     *
     * @return list<string>
     */
    public static function strings(array $body, string $field, int $max): array
    {
        $value = $body[$field] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
            throw TaskClientApiException::invalidField($field);
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw TaskClientApiException::invalidField($field);
            }
        }

        /* @var list<string> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public static function bool(array $body, string $field): bool
    {
        $value = $body[$field] ?? null;
        if (!is_bool($value)) {
            throw TaskClientApiException::invalidField($field);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public static function nullableInt(array $body, string $field, int $min, int $max): ?int
    {
        $value = $body[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw TaskClientApiException::invalidField($field);
        }

        return $value;
    }

    /**
     * An RFC 3339 date-time with an offset (`2026-11-27T09:00:00Z`, `…+01:00`), kept in UTC; null clears it.
     *
     * @param array<string, mixed> $body
     *
     * @throws TaskClientApiException
     */
    public static function date(array $body, string $field): ?DateTimeImmutable
    {
        $value = $body[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw TaskClientApiException::invalidField($field);
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', (string) preg_replace('/\.\d+/', '', $value));
        if ($date === false || (int) $date->format('Y') < 1970 || (int) $date->format('Y') > 9998) {
            throw TaskClientApiException::invalidField($field);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * `source`: `{type, ...}` — a type `[a-z][a-z0-9_]{0,31}` and at most 8 other string fields of at most 128 bytes.
     *
     * @throws TaskClientApiException
     */
    public static function source(mixed $source): TaskClientSource
    {
        if (!is_array($source) || array_is_list($source) || !is_string($source['type'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $source['type']) !== 1 || count($source) > 9) {
            throw TaskClientApiException::invalidField('source');
        }
        $fields = [];
        foreach ($source as $name => $value) {
            if ($name === 'type') {
                continue;
            }
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $name) !== 1 || !is_string($value) || strlen($value) > 128) {
                throw TaskClientApiException::invalidField('source');
            }
            $fields[$name] = $value;
        }

        return new TaskClientSource($source['type'], $fields);
    }

    /**
     * `If-Match: "<revision>"` (or without quotes); `*` is null (any). Missing is `428 precondition_required` when the
     * route requires it.
     *
     * @throws TaskClientApiException
     */
    public static function ifMatch(Request $request, bool $required, bool $anyAllowed = false): ?int
    {
        $header = $request->headers->get('If-Match');
        if ($header === null) {
            if ($required) {
                throw new TaskClientApiException('precondition_required', 428);
            }

            return null;
        }
        $header = trim($header);
        if ($header === '*') {
            if (!$anyAllowed) {
                throw new TaskClientApiException('precondition_required', 428);
            }

            return null;
        }
        if (preg_match('/^(?:W\/)?"?([1-9]\d{0,9})"?$/D', $header, $match) !== 1) {
            throw TaskClientApiException::invalidField('If-Match');
        }

        return (int) $match[1];
    }

    /**
     * `limit`: 1–100, `$default` when absent.
     *
     * @throws TaskClientApiException
     */
    public static function limit(Request $request, int $default, int $max = 100): int
    {
        $limit = $request->query->all()['limit'] ?? null;
        if ($limit === null) {
            return $default;
        }
        if (!is_string($limit) || preg_match('/^[1-9]\d{0,2}$/D', $limit) !== 1 || (int) $limit > $max) {
            throw new TaskClientApiException('invalid_limit', 400);
        }

        return (int) $limit;
    }
}
