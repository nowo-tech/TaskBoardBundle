<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldCondition;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function count;
use function ctype_digit;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function mb_strlen;
use function mb_strtolower;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Since 2.2.0 (spec 213): the filters of `GET /v2/list/{id}/task` and `GET /v2/team/{id}/task`, in ClickUp's names. Parameters
 * it does not know are ignored on reads; a malformed value is a 400, never a silent "no filter".
 */
final readonly class TaskApiTaskFilter
{
    public const PAGE_SIZE = 100;

    public const ORDERS = ['id', 'created', 'updated', 'due_date'];

    /** Since 2.16.0: the longest `query`. */
    public const QUERY_MAX = 200;

    /**
     * @param list<string> $statuses lower-case column names
     * @param list<string> $assigneeIds API ids
     * @param list<string> $tags
     * @param array<string, DateTimeImmutable> $dates `due_date_gt` and the like
     * @param list<string> $spaceIds team endpoint only
     * @param list<string> $listIds team endpoint only
     * @param list<TaskCustomFieldCondition> $customFields since 2.18.0 (FR-028): `custom_fields`, ClickUp's JSON filter
     */
    public function __construct(
        public int $page = 0,
        public string $orderBy = 'created',
        public bool $reverse = false,
        public bool $subtasks = false,
        public bool $includeClosed = false,
        public bool $archived = false,
        public array $statuses = [],
        public array $assigneeIds = [],
        public array $tags = [],
        public array $dates = [],
        public bool $markdown = false,
        public array $spaceIds = [],
        public array $listIds = [],
        public array $customFields = [],
        // Since 2.16.0 (spec 234, FR-026): `query`, the text search (null: none).
        public ?string $query = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query the request's query, as PHP parses `statuses[]=a&statuses[]=b`
     *
     * @throws TaskApiRefusal
     */
    public static function fromQuery(array $query): self
    {
        $page = $query['page'] ?? '0';
        if (!is_string($page) || !ctype_digit($page) || (int) $page > 100000) {
            throw self::invalid('page');
        }
        $orderBy = $query['order_by'] ?? 'created';
        if (!is_string($orderBy) || !in_array($orderBy, self::ORDERS, true)) {
            throw self::invalid('order_by');
        }
        $dates = [];
        foreach (['due_date', 'date_created', 'date_updated', 'date_done'] as $field) {
            foreach (['gt', 'lt'] as $side) {
                $name = $field . '_' . $side;
                if (isset($query[$name])) {
                    $dates[$name] = self::milliseconds($query[$name], $name);
                }
            }
        }

        return new self(
            page: (int) $page,
            orderBy: $orderBy,
            reverse: self::flag($query, 'reverse'),
            subtasks: self::flag($query, 'subtasks'),
            includeClosed: self::flag($query, 'include_closed'),
            archived: self::flag($query, 'archived'),
            statuses: array_map(static fn (string $status): string => mb_strtolower($status), self::list($query, 'statuses')),
            assigneeIds: self::list($query, 'assignees'),
            tags: array_map(static fn (string $tag): string => mb_strtolower($tag), self::list($query, 'tags')),
            dates: $dates,
            markdown: self::flag($query, 'include_markdown_description'),
            spaceIds: self::list($query, 'space_ids'),
            listIds: self::list($query, 'list_ids'),
            customFields: self::customFields($query['custom_fields'] ?? null),
            query: self::text($query),
        );
    }

    /**
     * Since 2.18.0 (FR-028): `custom_fields=[{"field_id": "…", "operator": "=", "value": "…"}]` (ClickUp's shape): operators
     * `=`, `!=`, `<`, `>`, `IS NOT NULL`, `IS NULL`, `ANY` (value: a list). At most 10 conditions; anything else is 400.
     *
     * @throws TaskApiRefusal
     *
     * @return list<TaskCustomFieldCondition>
     */
    public static function customFields(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true, 8) : null;
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) > 10) {
            throw self::invalid('custom_fields');
        }
        $conditions = [];
        foreach ($decoded as $item) {
            $operator = is_array($item) && is_string($item['operator'] ?? null) ? CustomFieldOperator::parse($item['operator']) : null;
            if (!is_array($item) || !is_string($item['field_id'] ?? null) || !$operator instanceof CustomFieldOperator) {
                throw self::invalid('custom_fields');
            }
            $value  = $item['value'] ?? null;
            $values = [];
            foreach (is_array($value) ? $value : ($value === null ? [] : [$value]) as $one) {
                if (!is_string($one) && !is_int($one) && !is_float($one)) {
                    throw self::invalid('custom_fields');
                }
                $values[] = (string) $one;
            }
            if (($operator->needsValue() && $values === []) || ($operator !== CustomFieldOperator::In && count($values) > 1) || count($values) > 100) {
                throw self::invalid('custom_fields');
            }
            $conditions[] = new TaskCustomFieldCondition($item['field_id'], $operator, $values);
        }

        return $conditions;
    }

    /**
     * Since 2.16.0: `query`, trimmed; null when absent or blank.
     *
     * @param array<string, mixed> $query
     */
    private static function text(array $query): ?string
    {
        $value = $query['query'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > self::QUERY_MAX) {
            throw self::invalid('query');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function flag(array $query, string $name): bool
    {
        $value = $query[$name] ?? null;
        if ($value === null) {
            return false;
        }
        if (!is_string($value) || !in_array(strtolower($value), ['true', 'false', '1', '0'], true)) {
            throw self::invalid($name);
        }

        return in_array(strtolower($value), ['true', '1'], true);
    }

    /**
     * Unix milliseconds, as ClickUp sends dates.
     *
     * @throws TaskApiRefusal
     */
    public static function milliseconds(mixed $value, string $name): DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^-?\d{1,15}$/D', $value) !== 1) {
            throw self::invalid($name);
        }

        return new DateTimeImmutable('@' . intdiv((int) $value, 1000));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private static function list(array $query, string $name): array
    {
        $value = $query[$name] ?? [];
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            throw self::invalid($name);
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private static function invalid(string $name): TaskApiRefusal
    {
        return new TaskApiRefusal(400, 'INPUT_002', 'Invalid ' . $name);
    }
}
