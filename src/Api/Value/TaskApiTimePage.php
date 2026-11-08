<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): the entries of one filter.
 */
final readonly class TaskApiTimePage
{
    /**
     * @param list<TaskApiTimeEntry> $entries
     */
    public function __construct(public array $entries)
    {
    }
}
