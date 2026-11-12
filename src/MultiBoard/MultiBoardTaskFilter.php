<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Enum\TaskPriority;

use function array_filter;
use function in_array;
use function is_string;
use function mb_substr;
use function preg_match;
use function trim;

/**
 * Since 2.13.0: the filters and sort of the multi-board list, its workload and its export, read from the URL. Everything is
 * validated here (enums, dates, lengths); the board and the assignee are only ever compared with what the viewer may
 * read, so a crafted value narrows the result or is ignored, never widens it.
 */
final readonly class MultiBoardTaskFilter
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_ALL = 'all';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_ALL];

    /** Assignee value of "nobody". */
    public const UNASSIGNED = 'none';

    /** Sort keys => first-click direction. */
    public const SORTS = [
        'due'      => 'asc',
        'priority' => 'desc',
        'title'    => 'asc',
        'board'    => 'asc',
        'column'   => 'asc',
        'number'   => 'asc',
        'created'  => 'desc',
        'updated'  => 'desc',
    ];

    public const DEFAULT_SORT = 'due';

    private const TEXT_MAX = 100;

    private const ID_MAX = 128;

    public function __construct(
        public ?string $boardId = null,
        public ?string $assigneeId = null,
        public ?string $column = null,
        public string $status = self::STATUS_OPEN,
        public ?TaskPriority $priority = null,
        public ?DateTimeImmutable $dueFrom = null,
        public ?DateTimeImmutable $dueTo = null,
        public bool $overdue = false,
        public ?string $label = null,
        public ?string $search = null,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = 'asc',
    ) {
    }

    /**
     * @param array<array-key, mixed> $query the request query (`all()`: a repeated parameter is ignored, not a 400)
     */
    public static function fromQuery(array $query): self
    {
        $text = static fn (string $key, int $max): ?string => is_string($query[$key] ?? null) && trim($query[$key]) !== '' ? mb_substr(trim($query[$key]), 0, $max) : null;
        $date = static function (string $key) use ($query): ?DateTimeImmutable {
            $value = $query[$key] ?? null;
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
                return null;
            }
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $day instanceof DateTimeImmutable && $day->format('Y-m-d') === $value ? $day : null;
        };

        $status    = $text('status', 8);
        $priority  = $text('priority', 16);
        $sort      = $text('sort', 16);
        $direction = $text('dir', 4);
        $sort      = $sort !== null && isset(self::SORTS[$sort]) ? $sort : self::DEFAULT_SORT;

        return new self(
            boardId: $text('board', self::ID_MAX),
            assigneeId: $text('assignee', self::ID_MAX),
            column: $text('column', self::TEXT_MAX),
            status: in_array($status, self::STATUSES, true) ? $status : self::STATUS_OPEN,
            priority: $priority !== null ? TaskPriority::tryFrom($priority) : null,
            dueFrom: $date('due_from'),
            dueTo: $date('due_to'),
            overdue: in_array($query['overdue'] ?? null, ['1', 'true', 'on'], true),
            label: $text('label', self::TEXT_MAX),
            search: $text('q', self::TEXT_MAX),
            sort: $sort,
            direction: in_array($direction, ['asc', 'desc'], true) ? $direction : self::SORTS[$sort],
        );
    }

    /** The same filter without a board (a board the viewer may not read is ignored). */
    public function withoutBoard(): self
    {
        return new self(null, $this->assigneeId, $this->column, $this->status, $this->priority, $this->dueFrom, $this->dueTo, $this->overdue, $this->label, $this->search, $this->sort, $this->direction);
    }

    /** The same filter without an assignee (an assignee with no task on the readable boards is ignored). */
    public function withoutAssignee(): self
    {
        return new self($this->boardId, null, $this->column, $this->status, $this->priority, $this->dueFrom, $this->dueTo, $this->overdue, $this->label, $this->search, $this->sort, $this->direction);
    }

    /**
     * The query that reproduces the filters (not the sort) in a link.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'board'    => $this->boardId,
            'assignee' => $this->assigneeId,
            'column'   => $this->column,
            'status'   => $this->status !== self::STATUS_OPEN ? $this->status : null,
            'priority' => $this->priority?->value,
            'due_from' => $this->dueFrom?->format('Y-m-d'),
            'due_to'   => $this->dueTo?->format('Y-m-d'),
            'overdue'  => $this->overdue ? '1' : null,
            'label'    => $this->label,
            'q'        => $this->search,
        ], static fn (?string $value): bool => $value !== null);
    }

    public function isActive(): bool
    {
        return $this->toQuery() !== [];
    }
}
