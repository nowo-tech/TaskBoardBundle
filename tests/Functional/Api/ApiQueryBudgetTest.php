<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

use function count;
use function sprintf;

use const STDERR;

/**
 * Spec 213 SC-003 and FR-010: a list costs the same number of queries with 25 tasks as with 100, each task with members,
 * subtasks, a dependency, time and a comment. The budget counts every query of the request, the user's included.
 */
final class ApiQueryBudgetTest extends ApiTestCase
{
    /** The most queries a list endpoint may make, whatever the page holds. */
    private const BUDGET = 12;

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function endpoints(): iterable
    {
        yield 'list tasks' => ['/api/tasks/v2/list/{board}/task?subtasks=true&include_closed=true'];
        yield 'team tasks' => ['/api/tasks/v2/team/team-1/task?subtasks=true'];
        yield 'list tasks for my tasks' => ['my:/api/tasks/v2/list/{board}/task'];
        yield 'lists of a space' => ['/api/tasks/v2/space/space-tenant-a/list'];
        yield 'tags of a space' => ['/api/tasks/v2/space/space-tenant-a/tag'];
        yield 'comments' => ['/api/tasks/v2/task/{first}/comment'];
        yield 'list tasks by tag' => ['/api/tasks/v2/list/{board}/task?tags[]=tag-1&subtasks=true'];
        yield 'team tasks by tag' => ['/api/tasks/v2/team/team-1/task?tags[]=tag-2'];
    }

    #[DataProvider('endpoints')]
    public function testTheSameQueriesFor25And100Tasks(string $endpoint): void
    {
        $small = $this->queriesFor($endpoint, 25);
        $large = $this->queriesFor($endpoint, 100);

        self::assertSame($small, $large, 'no query per task');
        self::assertLessThanOrEqual(self::BUDGET, $large);
        fwrite(STDERR, sprintf("\n[budget] %s: %d queries at 25 tasks, %d at 100\n", $endpoint, $small, $large));
    }

    /**
     * Audit IA-I8: each board a team request reaches costs its access decision (an event, in the host often a voter), not a
     * query of the bundle's: the same queries with 1 board as with 10.
     */
    public function testTeamTasksCostTheSameQueriesForOneBoardOrTen(): void
    {
        $one = $this->teamQueriesFor(1);
        $ten = $this->teamQueriesFor(10);

        self::assertSame($one, $ten);
        fwrite(STDERR, sprintf("\n[budget] team tasks: %d queries with 1 board, %d with 10\n", $one, $ten));
    }

    private function teamQueriesFor(int $boards): int
    {
        self::ensureKernelShutdown();
        $this->setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        for ($i = 0; $i < $boards; ++$i) {
            $this->seed($this->board('Board ' . $i, tenantKey: 'tenant-a'), 3);
        }
        $this->issueToken();
        $this->em->clear();

        return $this->counted('/api/tasks/v2/team/team-1/task?subtasks=true');
    }

    private function counted(string $path): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $this->get($path);
        self::assertResponseIsSuccessful();
        $count = 0;
        foreach ($holder->getData() as $queries) {
            $count += count($queries);
        }

        return $count;
    }

    private function queriesFor(string $endpoint, int $tasks): int
    {
        self::ensureKernelShutdown();
        $this->setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $board                = $this->board('Board', tenantKey: 'tenant-a');
        $first                = $this->seed($board, $tasks);
        $level                = str_starts_with($endpoint, 'my:') ? TaskApiLevel::MyTasks : TaskApiLevel::Full;
        $this->issueToken($level);
        $this->em->clear();
        $path = strtr((string) preg_replace('/^my:/', '', $endpoint), ['{board}' => $board->getId(), '{first}' => $first]);

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $this->get($path);
        self::assertResponseIsSuccessful();
        $count = 0;
        foreach ($holder->getData() as $queries) {
            $count += count($queries);
        }

        return $count;
    }

    private function seed(TaskBoard $board, int $count): string
    {
        $colleague = $this->user('colleague-' . $count . '-' . $board->getId() . '@example.com');
        $previous  = null;
        $first     = null;
        for ($i = 0; $i < $count; ++$i) {
            $task = new Task(board: $board, title: 'Task ' . $i, creator: $this->owner, column: $board->getColumns()->get($i % 3), position: $i, tags: ['tag-' . ($i % 5)]);
            $task->addMember(new TaskMember(task: $task, user: $colleague, memberRole: TaskMemberRole::Assignee));
            $task->addMember(new TaskMember(task: $task, user: $this->owner, memberRole: TaskMemberRole::Watcher));
            $this->em->persist($task);
            $this->em->persist(new Task(board: $board, title: 'Item ' . $i, creator: $this->owner, parent: $task, tags: ['checklist:Steps']));
            $this->em->persist(new TaskTimeEntry($task, $this->owner, 15, new DateTimeImmutable('2026-11-01')));
            $this->em->persist(new TaskComment($task, $colleague, 'Comment ' . $i));
            if ($previous instanceof Task) {
                $this->em->persist(new TaskDependency($previous, $task, TaskDependencyType::Blocks));
            }
            $previous = $task;
            $first ??= $task;
        }
        for ($i = 0; $i < $count; ++$i) {
            $this->em->persist(new TaskComment($first, $colleague, 'On the first ' . $i));
        }
        $this->em->flush();

        return $first->getId();
    }
}
