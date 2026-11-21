<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Dto\TaskCriteria;
use Nowo\TaskBoardBundle\View\BoardPage;
use Nowo\TaskBoardBundle\View\BoardView;
use Nowo\TaskBoardBundle\View\TaskPage;
use Nowo\TaskBoardBundle\View\TaskView;

/**
 * Since 2.29.0 (DDD-host checklist B7, REQ-ARCH-001 rules 1 and 3): the read port over boards and tasks for a host, so
 * it never writes DQL over the bundle's entities (lists, counts, due windows, tag histograms). Implemented by
 * {@see TaskBoardQuery} on the bundle's entity manager (the private one with `database.private_entity_manager: true`).
 *
 * It is a **system read**: it checks no access. A host that shows the result to a user decides with its own rules, or
 * with {@see TaskBoardAccessInterface} (by id), first.
 *
 * Tenant keys and ids are matched byte for byte (house rule: a `*_ci` collation never mixes tenants): the database
 * selects the candidates and PHP keeps only the rows whose key is `===` one asked. Pages are bounded
 * ({@see TaskCriteria::MAX_LIMIT}, {@see BoardCriteria::MAX_LIMIT}); counts are exact per key.
 */
interface TaskBoardQueryInterface
{
    /** One page of the boards of the criteria's tenants, by name then id, and the total. */
    public function findBoards(BoardCriteria $criteria): BoardPage;

    /** The board with exactly this id (archived included), or null. */
    public function findBoardById(string $boardId): ?BoardView;

    /**
     * How many active (not archived) boards each tenant has; every asked usable key is in the answer (0 when none).
     *
     * @param list<string> $tenantKeys at most {@see BoardCriteria::MAX_LIMIT}
     *
     * @throws InvalidArgumentException with more keys than that
     *
     * @return array<string, int> tenant key => boards
     */
    public function countActiveBoards(array $tenantKeys): array;

    /** One page of the tasks that match the criteria, in the criteria's order, and the total. */
    public function findTasks(TaskCriteria $criteria): TaskPage;

    /** How many tasks match the criteria (the page is ignored). */
    public function countTasks(TaskCriteria $criteria): int;

    /** The task with exactly this id (archived included), or null. */
    public function findTaskById(string $taskId): ?TaskView;

    /**
     * The tasks with these ids, in the order of the ids; unknown ids are left out.
     *
     * @param list<string> $taskIds at most {@see TaskCriteria::MAX_LIMIT}
     *
     * @throws InvalidArgumentException with more ids than that
     *
     * @return list<TaskView>
     */
    public function findTasksByIds(array $taskIds): array;

    /**
     * How many open tasks (not completed, not archived; subtasks included) each tenant has, on its active boards; every
     * asked usable key is in the answer (0 when none).
     *
     * @param list<string> $tenantKeys at most {@see TaskCriteria::MAX_LIMIT}
     *
     * @throws InvalidArgumentException with more keys than that
     *
     * @return array<string, int> tenant key => open tasks
     */
    public function countOpenTasks(array $tenantKeys): array;

    /**
     * How many tasks carry each tag, over the active boards of the tenants (open tasks only unless `$openOnly` is false;
     * archived tasks never). Tags as stored; the most used first, then by tag.
     *
     * @param list<string> $tenantKeys at most {@see TaskCriteria::MAX_LIMIT}
     *
     * @throws InvalidArgumentException with more keys than that
     *
     * @return array<string, int> tag => tasks
     */
    public function tagCounts(array $tenantKeys, bool $openOnly = true): array;
}
