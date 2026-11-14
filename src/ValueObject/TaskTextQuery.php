<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use Nowo\TaskBoardBundle\Search\TaskTextSearchInterface;

/**
 * Since 2.16.0 (spec 234, FR-026): a text search over tasks, as {@see TaskTextSearchInterface}
 * gets it. `$boardIds` are the boards the caller may read, already checked: a match outside them must never be returned.
 * `$limit` is how many ids are wanted at most; one more tells the caller the query is too broad.
 */
final readonly class TaskTextQuery
{
    /**
     * @param list<string> $boardIds
     */
    public function __construct(
        public string $text,
        public array $boardIds,
        public int $limit,
    ) {
    }
}
