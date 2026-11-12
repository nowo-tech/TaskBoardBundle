<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\MultiBoard;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedMultiBoardScope;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 2.13.0: the functional kernel with `multi_board.enabled` and the scripted host scope (`app.multi_scope`).
 */
abstract class MultiBoardTestCase extends FunctionalTestCase
{
    protected const CONFIG = ['multi_board' => ['enabled' => true, 'scope_provider' => 'app.multi_scope'], 'task_numbers' => ['enabled' => true]];

    protected const URL = '/tools/task-board/tasks';

    protected function setUp(): void
    {
        static::$taskBoardConfig = static::$taskBoardConfig !== [] ? static::$taskBoardConfig : static::CONFIG;
        parent::setUp();
    }

    protected function scope(): ScriptedMultiBoardScope
    {
        $scope = static::getContainer()->get('app.multi_scope');
        self::assertInstanceOf(ScriptedMultiBoardScope::class, $scope);

        return $scope;
    }

    /**
     * The boards `$user` may read through the host's scope.
     */
    protected function allow(AppUser $user, TaskBoard|MultiBoardScopeEntry ...$boards): void
    {
        $this->scope()->entries[(string) $user->getId()] = array_map(static fn (TaskBoard|MultiBoardScopeEntry $board): MultiBoardScopeEntry => $board instanceof TaskBoard ? new MultiBoardScopeEntry($board->getId()) : $board, $boards);
    }

    /**
     * @param array{priority?: TaskPriority, due?: string|null, start?: string|null, tags?: list<string>, estimate?: int|null, logged?: int, completed?: bool, assignees?: list<AppUser>, column?: int} $options
     */
    protected function make(TaskBoard $board, string $title, array $options = []): Task
    {
        $task = new Task(board: $board, title: $title, creator: $board->getCreator(), column: $board->getColumns()->get($options['column'] ?? 0), priority: $options['priority'] ?? TaskPriority::Normal, estimatedMinutes: $options['estimate'] ?? null, dueAt: isset($options['due']) ? new DateTimeImmutable($options['due']) : null, tags: $options['tags'] ?? []);
        if (isset($options['start'])) {
            $task->setStartAt(new DateTimeImmutable($options['start']));
        }
        if (($options['logged'] ?? 0) > 0) {
            $task->addTimeSeconds($options['logged'] * 60);
        }
        if ($options['completed'] ?? false) {
            $task->markCompleted();
        }
        foreach ($options['assignees'] ?? [] as $user) {
            $task->addMember(new TaskMember($task, $user, TaskMemberRole::Assignee));
        }
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /**
     * The titles of the task rows of the last page, in order.
     *
     * @return list<string>
     */
    protected function titles(): array
    {
        return $this->client->getCrawler()->filter('[data-testid="nowo-task-multi-title"]')->each(static fn ($node): string => $node->text());
    }

    protected function get(string $query = ''): string
    {
        $this->client->request('GET', static::URL . ($query !== '' ? '?' . $query : ''));

        return (string) $this->client->getResponse()->getContent();
    }

    protected function export(string $format, string $query = ''): string
    {
        $this->client->request('GET', static::URL . '/export.' . $format . ($query !== '' ? '?' . $query : ''));

        // A streamed response: the browser keeps what it sent.
        return $this->client->getInternalResponse()->getContent();
    }
}
