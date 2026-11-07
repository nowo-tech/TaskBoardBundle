<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.2.0 (spec 213): the default time store: none, until the host sets `api.hooks.time_entries`.
 */
final readonly class DenyingTaskApiTimeEntries implements TaskApiTimeEntriesInterface
{
    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
    {
        throw $this->refusal();
    }

    public function log(TaskApiPrincipal $principal, ?Task $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
    {
        throw $this->refusal();
    }

    private function refusal(): TaskApiRefusal
    {
        return new TaskApiRefusal(403, 'TIME_001', 'Time entries are not available');
    }
}
