<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search;

use Nowo\TaskBoardBundle\ValueObject\TaskTextQuery;

/**
 * Since 2.16.0 (spec 234, FR-026): which tasks of the given boards match a text (title, description and, optionally,
 * comments). The user's text is plain words: no operator of any search engine may come through. Set `search.provider`
 * to a service implementing it; default {@see DoctrineTaskTextSearch}. Framework-free; keep no state between calls.
 *
 * The bundle then applies every other filter, the caller's reach (*My tasks*) and the paging to these ids, so the port
 * returns every match within the boards up to `$query->limit + 1`, never a page.
 */
interface TaskTextSearchInterface
{
    /**
     * @return list<string> task ids, each of a board in `$query->boardIds`
     */
    public function matchingTaskIds(TaskTextQuery $query): array;
}
