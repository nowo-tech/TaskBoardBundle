<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeStoreInterface;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\Value\TaskApiNewTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeEntry;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimePage;
use Nowo\TaskBoardBundle\Api\Value\TaskApiTimeQuery;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.15.0: asks a host {@see TaskApiTimeStoreInterface} where the bundle asks the deprecated {@see TaskApiTimeEntriesInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskApiTimeStoreGate implements TaskApiTimeEntriesInterface
{
    public function __construct(private TaskApiTimeStoreInterface $store)
    {
    }

    public function list(TaskApiPrincipal $principal, TaskApiTimeQuery $query): TaskApiTimePage
    {
        return $this->store->list($principal, $query);
    }

    public function log(TaskApiPrincipal $principal, ?Task $task, TaskApiNewTimeEntry $entry): TaskApiTimeEntry
    {
        return $this->store->log($principal, $task instanceof Task ? Snapshots::task($task) : null, $entry);
    }
}
