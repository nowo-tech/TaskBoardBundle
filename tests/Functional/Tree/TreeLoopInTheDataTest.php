<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Tree;

use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Copy\TaskCopyResult;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureInterface;
use Nowo\TaskBoardBundle\Folder\TaskBoardRestructureRefusedException;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Move\TaskMovePlan;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskTreeStore;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Support\SubtaskProgress;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Tests\Functional\Api\ApiTestCase;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Nowo\TaskBoardBundle\Tree\TaskTreeLimits;

use function array_filter;
use function str_contains;

/**
 * Audit PX-M2 (TaskBoard 2.20.0): an A ↔ B loop already in the data (written before 2.20 by an unlocked import or sync,
 * or by hand). Every page, walk and write terminates: the reads show what they can, the writes that would walk it are
 * refused (`tree_loop`, PARENT_003), and a detach repairs it. Before 2.20 the task pages segfaulted the worker.
 */
final class TreeLoopInTheDataTest extends ApiTestCase
{
    private TaskBoard $roadmap;

    private Task $a;

    private Task $b;

    protected function setUp(): void
    {
        self::$hostConfig = ['max_subtask_depth' => 5];
        parent::setUp();
        ApiTestHost::$tenants = ['tenant-a'];
        $this->roadmap        = $this->board('Roadmap', tenantKey: 'tenant-a');
        $this->a              = $this->task($this->roadmap, 'A');
        $this->b              = $this->task($this->roadmap, 'B', parent: $this->a);
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET parent_id = ? WHERE id = ?', [$this->b->getId(), $this->a->getId()]);
        $this->em->clear();
        $a       = $this->em->find(Task::class, $this->a->getId());
        $b       = $this->em->find(Task::class, $this->b->getId());
        $roadmap = $this->em->find(TaskBoard::class, $this->roadmap->getId());
        $owner   = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertInstanceOf(Task::class, $a);
        self::assertInstanceOf(Task::class, $b);
        self::assertInstanceOf(TaskBoard::class, $roadmap);
        self::assertNotNull($owner);
        [$this->a, $this->b, $this->roadmap, $this->owner] = [$a, $b, $roadmap, $owner];
        $this->issueToken();
    }

    public function testPagesAndReadsTerminate(): void
    {
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/task/' . $this->a->getId());
        self::assertResponseIsSuccessful();
        $board = $this->em->find(TaskBoard::class, $this->roadmap->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        foreach (['', '/list', '/gantt'] as $view) {
            $this->client->request('GET', '/tools/task-board/' . $board->getId() . $view);
            self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), $view);
        }
        $this->get('/api/tasks/v2/task/' . $this->a->getId());
        self::assertResponseIsSuccessful();

        $a = $this->em->find(Task::class, $this->a->getId());
        self::assertInstanceOf(Task::class, $a);
        self::assertSame(1, SubtaskProgress::of($a)->total, 'B once');
        $tree = TaskTree::fromTasks([$a, $a->getParent() ?? $a]);
        self::assertSame(1, $tree->progress($a)->total);
        self::assertCount(1, $tree->descendantsOf($a));
    }

    public function testWritesThatWalkTheLoopFailClosed(): void
    {
        $c     = $this->task($this->roadmap, 'C');
        $other = $this->board('Other', tenantKey: 'tenant-a');
        $this->send('PUT', '/api/tasks/v2/task/' . $c->getId(), ['parent' => $this->a->getId()]);
        $this->assertRefused(409, 'PARENT_003');
        $this->send('PUT', '/api/tasks/v2/task/' . $this->a->getId(), ['name' => 'Renamed']);
        self::assertResponseIsSuccessful();

        $planner = self::getContainer()->get(TaskMovePlanner::class);
        self::assertInstanceOf(TaskMovePlanner::class, $planner);
        $a = $this->em->find(Task::class, $this->a->getId());
        self::assertInstanceOf(Task::class, $a);
        $this->assertCopyRefused('tree_loop', fn (): TaskMovePlan => $planner->plan($a, $other, [], $this->owner));
        $this->assertCopyRefused('tree_loop', static fn (): string => $planner->snapshot($a));
        $copier = self::getContainer()->get(TaskCopier::class);
        self::assertInstanceOf(TaskCopier::class, $copier);
        $this->assertCopyRefused('tree_loop', fn (): TaskCopyResult => $copier->copy($a, new TaskCopyOptions(), $this->owner));
    }

    public function testADetachRepairsTheLoopAndAnErasureEnds(): void
    {
        $this->send('PUT', '/api/tasks/v2/task/' . $this->a->getId(), ['parent' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE id = ?', [$this->a->getId()]));

        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET parent_id = ? WHERE id = ?', [$this->b->getId(), $this->a->getId()]);
        $this->em->clear();
        $a = $this->em->find(Task::class, $this->a->getId());
        self::assertInstanceOf(Task::class, $a);
        $eraser = self::getContainer()->get(TaskBoardEraser::class);
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);
        self::assertSame(2, $eraser->eraseTask($a, $this->owner));
    }

    public function testImportsAndTheStoreFailClosedOnTheLoop(): void
    {
        $import = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $import);
        $result = $import->import($this->roadmap, TaskImportSource::TaskBoardCsv, "id,parent,title\nx,,Row\n", 'plan.csv', $this->owner, parentTask: $this->a);
        self::assertSame(1, $result->created);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE title = ?', ['Row']), 'not under the loop');
        self::assertNotSame([], array_filter($result->warnings, static fn (string $warning): bool => str_contains($warning, 'task created at root level')));

        $store = self::getContainer()->get(DoctrineOrmTaskTreeStore::class);
        self::assertInstanceOf(DoctrineOrmTaskTreeStore::class, $store);
        self::assertSame(TaskTreeLimits::UNBOUNDED, $store->transactional(fn (): int => $store->lockedHeight($this->a->getId(), 5)));
    }

    public function testARestructureThroughTheLoopIsRefused(): void
    {
        $restructure = self::getContainer()->get(TaskBoardRestructureInterface::class);
        self::assertInstanceOf(TaskBoardRestructureInterface::class, $restructure);
        $other = $this->board('Other', tenantKey: 'tenant-a');

        try {
            $restructure->moveTasks($this->owner, [$this->a->getId()], $other->getId());
            self::fail('Refused expected.');
        } catch (TaskBoardRestructureRefusedException $refused) {
            self::assertSame(TaskBoardRestructureRefusedException::TREE_LOOP, $refused->reason);
        }
        self::assertSame($this->roadmap->getId(), (string) $this->em->getConnection()->fetchOne('SELECT board_id FROM task_board_tasks WHERE id = ?', [$this->a->getId()]));
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }

    private function assertCopyRefused(string $reason, callable $write): void
    {
        try {
            $write();
            self::fail('Refused expected: ' . $reason);
        } catch (TaskCopyRefusedException $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
