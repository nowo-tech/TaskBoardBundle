<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Enum\TaskSort;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Support\ExactKey;

use function array_key_exists;
use function array_values;
use function count;
use function sprintf;

/**
 * Since 2.29.0 (DDD-host checklist B7, REQ-ARCH-001 rule 3): what a task query of {@see TaskBoardQueryInterface} looks
 * for: the tasks of some tenants or of some boards, with optional filters, an order and a bounded page. Immutable; built
 * with {@see self::forTenants()} / {@see self::forBoards()} and the `with*()` methods.
 *
 * Tenant keys and ids are matched byte for byte: the database selects candidates, and PHP keeps only the rows whose key
 * is `===` one asked (a `*_ci` collation never mixes tenants).
 */
final readonly class TaskCriteria
{
    /** Largest page, and most tenant keys or board ids in one query. */
    public const MAX_LIMIT = 500;

    public const DEFAULT_LIMIT = 50;

    /**
     * @param list<string> $tenantKeys
     * @param list<string> $boardIds
     * @param bool|null $completed true: completed only; false: open only; null: both
     * @param bool|null $archived true: archived only; false (default): active only; null: both
     *
     * @throws InvalidArgumentException
     */
    private function __construct(
        public array $tenantKeys,
        public array $boardIds,
        public ?bool $completed = null,
        public ?bool $archived = false,
        public ?DateTimeImmutable $dueFrom = null,
        public ?DateTimeImmutable $dueTo = null,
        public ?string $columnId = null,
        public ?string $creatorId = null,
        public bool $topLevelOnly = false,
        public TaskSort $sort = TaskSort::CreatedDesc,
        public int $offset = 0,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if (count($tenantKeys) > self::MAX_LIMIT || count($boardIds) > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('TaskBoard: at most %d tenant keys or board ids per query.', self::MAX_LIMIT));
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('TaskBoard: the offset cannot be negative.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('TaskBoard: the limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        if ($dueFrom instanceof DateTimeImmutable && $dueTo instanceof DateTimeImmutable && $dueFrom > $dueTo) {
            throw new InvalidArgumentException('TaskBoard: the due window starts after it ends.');
        }
    }

    /**
     * The tasks of the boards of these tenants (each key matched exactly; an unusable key matches nothing).
     *
     * @param list<string> $tenantKeys
     */
    public static function forTenants(array $tenantKeys): self
    {
        $keys = [];
        foreach ($tenantKeys as $key) {
            if (ExactKey::isUsable($key)) {
                $keys[$key] = $key;
            }
        }

        return new self(array_values($keys), []);
    }

    /**
     * The tasks of these boards.
     *
     * @param list<string> $boardIds
     */
    public static function forBoards(array $boardIds): self
    {
        $ids = [];
        foreach ($boardIds as $id) {
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return new self([], array_values($ids));
    }

    /** Whether the criteria can match nothing (no usable key or id). */
    public function isEmpty(): bool
    {
        return $this->tenantKeys === [] && $this->boardIds === [];
    }

    /** Open (false), completed (true) or both (null, default). */
    public function withCompleted(?bool $completed): self
    {
        return $this->copy(['completed' => $completed]);
    }

    /** Active (false, default), archived (true) or both (null). */
    public function withArchived(?bool $archived): self
    {
        return $this->copy(['archived' => $archived]);
    }

    /**
     * Only tasks due in this window (bounds included; null: open on that side). Tasks without a due date never match.
     *
     * @throws InvalidArgumentException when the window starts after it ends
     */
    public function dueBetween(?DateTimeImmutable $from, ?DateTimeImmutable $to): self
    {
        return $this->copy(['dueFrom' => $from, 'dueTo' => $to]);
    }

    /** Only tasks in this column (null: any). */
    public function inColumn(?string $columnId): self
    {
        return $this->copy(['columnId' => $columnId === '' ? null : $columnId]);
    }

    /** Only tasks created by exactly this user id (null: anyone). */
    public function createdBy(?string $userId): self
    {
        return $this->copy(['creatorId' => $userId === '' ? null : $userId]);
    }

    /** Only tasks without a parent (subtasks left out). */
    public function topLevelOnly(bool $topLevelOnly = true): self
    {
        return $this->copy(['topLevelOnly' => $topLevelOnly]);
    }

    public function sortedBy(TaskSort $sort): self
    {
        return $this->copy(['sort' => $sort]);
    }

    /**
     * @throws InvalidArgumentException when the offset is negative or the limit out of range
     */
    public function page(int $offset, int $limit = self::DEFAULT_LIMIT): self
    {
        return $this->copy(['offset' => $offset, 'limit' => $limit]);
    }

    /**
     * @param array{completed?: bool|null, archived?: bool|null, dueFrom?: DateTimeImmutable|null, dueTo?: DateTimeImmutable|null, columnId?: string|null, creatorId?: string|null, topLevelOnly?: bool, sort?: TaskSort, offset?: int, limit?: int} $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            $this->tenantKeys,
            $this->boardIds,
            array_key_exists('completed', $changes) ? $changes['completed'] : $this->completed,
            array_key_exists('archived', $changes) ? $changes['archived'] : $this->archived,
            array_key_exists('dueFrom', $changes) ? $changes['dueFrom'] : $this->dueFrom,
            array_key_exists('dueTo', $changes) ? $changes['dueTo'] : $this->dueTo,
            array_key_exists('columnId', $changes) ? $changes['columnId'] : $this->columnId,
            array_key_exists('creatorId', $changes) ? $changes['creatorId'] : $this->creatorId,
            $changes['topLevelOnly'] ?? $this->topLevelOnly,
            $changes['sort'] ?? $this->sort,
            $changes['offset'] ?? $this->offset,
            $changes['limit'] ?? $this->limit,
        );
    }
}
