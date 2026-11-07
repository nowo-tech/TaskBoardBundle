<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Enum\TaskPriority;

use function array_filter;
use function in_array;
use function is_string;
use function mb_substr;
use function trim;

/**
 * Since 2.1.0 (spec 214, FR-021): the filters of the calendar, read from the URL and applied by the server, because the
 * visible range bounds the query. Completed tasks and subtasks stay hidden unless asked for.
 */
final readonly class TaskCalendarFilter
{
    private const TAG_MAX = 100;

    public function __construct(
        public ?string $assignee = null,
        public ?TaskPriority $priority = null,
        public ?string $tag = null,
        public ?string $columnId = null,
        public bool $completed = false,
        public bool $subtasks = false,
    ) {
    }

    /**
     * Anything not offered on this board is ignored, so a value in a URL never reaches a query as a guess.
     *
     * @param array<array-key, mixed> $query
     * @param list<string> $assignees the assignee ids of the board
     * @param list<string> $columnIds the column ids of the board
     */
    public static function fromQuery(array $query, array $assignees, array $columnIds): self
    {
        $text = static fn (string $key): ?string => is_string($query[$key] ?? null) && trim($query[$key]) !== '' ? trim($query[$key]) : null;
        $flag = static fn (string $key): bool => in_array($query[$key] ?? null, ['1', 'true', 'on'], true);

        $assignee = $text('assignee');
        $column   = $text('column');
        $priority = $text('priority');
        $tag      = $text('tag');

        return new self(
            assignee: in_array($assignee, $assignees, true) ? $assignee : null,
            priority: $priority !== null ? TaskPriority::tryFrom($priority) : null,
            tag: $tag !== null ? mb_substr($tag, 0, self::TAG_MAX) : null,
            columnId: in_array($column, $columnIds, true) ? $column : null,
            completed: $flag('completed'),
            subtasks: $flag('subtasks'),
        );
    }

    /**
     * The query that reproduces this filter in a link.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'assignee'  => $this->assignee,
            'priority'  => $this->priority?->value,
            'tag'       => $this->tag,
            'column'    => $this->columnId,
            'completed' => $this->completed ? '1' : null,
            'subtasks'  => $this->subtasks ? '1' : null,
        ], static fn (?string $value): bool => $value !== null);
    }

    public function isActive(): bool
    {
        return $this->toQuery() !== [];
    }
}
