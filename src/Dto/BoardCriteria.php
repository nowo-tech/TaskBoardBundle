<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Support\ExactKey;

use function array_values;
use function count;
use function sprintf;

/**
 * Since 2.29.0 (DDD-host checklist B7): what a board query of {@see TaskBoardQueryInterface} looks for: the boards of
 * some tenants (keys matched byte for byte), active, archived or both, ordered by name then id, in a bounded page.
 */
final readonly class BoardCriteria
{
    public const MAX_LIMIT = 500;

    public const DEFAULT_LIMIT = 100;

    /**
     * @param list<string> $tenantKeys
     * @param bool|null $archived false (default): active only; true: archived only; null: both
     *
     * @throws InvalidArgumentException
     */
    private function __construct(
        public array $tenantKeys,
        public ?bool $archived = false,
        public int $offset = 0,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
        if (count($tenantKeys) > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('TaskBoard: at most %d tenant keys per query.', self::MAX_LIMIT));
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('TaskBoard: the offset cannot be negative.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('TaskBoard: the limit must be between 1 and %d.', self::MAX_LIMIT));
        }
    }

    /**
     * @param list<string> $tenantKeys each matched exactly; an unusable key matches nothing
     */
    public static function forTenants(array $tenantKeys): self
    {
        $keys = [];
        foreach ($tenantKeys as $key) {
            if (ExactKey::isUsable($key)) {
                $keys[$key] = $key;
            }
        }

        return new self(array_values($keys));
    }

    public function withArchived(?bool $archived): self
    {
        return new self($this->tenantKeys, $archived, $this->offset, $this->limit);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function page(int $offset, int $limit = self::DEFAULT_LIMIT): self
    {
        return new self($this->tenantKeys, $this->archived, $offset, $limit);
    }
}
