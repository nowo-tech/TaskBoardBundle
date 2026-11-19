<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Search\SearchKit;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\TaskBoardBundle\Bridge\SearchKit\TaskSearchDefinition;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 2.25.0: SearchKit's provider contract for the `tasks` group. Hidden rows are tasks TASK_ACCESS_CHECK denies;
 * archived rows are archived tasks.
 */
final class TaskSearchContractTest extends TaskBoardSearchKitTestCase
{
    protected function definition(): EntitySearchDefinition
    {
        return $this->definitionService(TaskSearchDefinition::class);
    }

    /** Audit Q2: task titles and excerpts may hold personal data (`search_kit.tasks.sensitive`, default true). */
    protected function sensitiveTitles(): bool
    {
        return true;
    }

    /**
     * SearchKit >= 1.10.1: a sensitive group is expected to filter hidden rows in SQL. The bundle does so for everything it
     * decides itself (the boards the viewer may open — creator, team, BOARD_ACCESS_CHECK —, archived tasks, the scope), but a
     * host's TASK_ACCESS_CHECK veto is a PHP event, so the contract's hidden rows (vetoed tasks) are filtered after the query.
     */
    protected function hiddenRowsFilteredInSql(): bool
    {
        return false;
    }

    protected function seed(int|string $tenant, string $text): int|string
    {
        return $this->task($tenant, $text)->getId();
    }

    protected function seedHidden(int|string $tenant, string $text): int|string
    {
        $id                = $this->task($tenant, $text)->getId();
        $this->denied[$id] = true;

        return $id;
    }

    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        $task = $this->task($tenant, $text);
        $task->archive();
        $this->em->flush();

        return $task->getId();
    }

    protected function reference(int|string $id): SearchReference
    {
        return $this->referenceFor('nowo_task_board_task', 'taskId', $id);
    }

    private function task(int|string $tenant, string $text): Task
    {
        $task = new Task($this->tenantBoard($tenant), $text, $this->users[self::VIEWER_A]);
        $this->em->persist($task);
        $this->em->flush();
        $this->tenantOfRow[$task->getId()] = (string) $tenant;

        return $task;
    }
}
