<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Tree;

use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Service\TaskManagerInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use function str_contains;

/**
 * Beacon spec 234, T004 (TaskBoard 2.20.0): `max_subtask_depth: 1` on every write that changes depth outside the Task
 * API: in-process create, the board UI's subtask form, copies, and imports (rows go under their deepest allowed ancestor).
 */
final class DepthLimitWritesTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        self::$taskBoardConfig = ['max_subtask_depth' => 1];
        parent::setUp();
    }

    public function testInProcessCreateIsRefusedPastTheLimit(): void
    {
        $board = $this->board();
        $child = $this->task($board, 'Child', parent: $this->task($board, 'Root'));
        $tasks = self::getContainer()->get(TaskManagerInterface::class);
        self::assertInstanceOf(TaskManagerInterface::class, $tasks);

        try {
            $tasks->create($board, new TaskFormData('Too deep'), $this->owner, $child);
            self::fail('Refused expected.');
        } catch (TaskTreeRefusedException $refused) {
            self::assertSame(TaskTreeRefusedException::TOO_DEEP, $refused->reason);
        }
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Too deep']));
    }

    public function testTheSubtaskFormFlashesTheRefusal(): void
    {
        $board  = $this->board();
        $child  = $this->task($board, 'Child', parent: $this->task($board, 'Root'));
        $column = $board->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $column);
        $this->login($this->owner);

        $this->client->request('POST', '/tools/task-board/task/' . $child->getId() . '/subtask', ['task_form' => ['title' => 'Too deep', 'columnId' => $column->getId(), '_token' => PredictableCsrfTokenManager::value('task_form')]]);

        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Too deep']));
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['The subtask was not created: it would be deeper than this board allows.'], $session->getFlashBag()->get('error'));
    }

    public function testACopyNeverCreatesATaskPastTheLimit(): void
    {
        $board  = $this->board();
        $source = $this->task($board, 'Source');
        $this->task($board, 'Its child', parent: $source);
        $nested = $this->task($board, 'Nested', parent: $source);
        $this->task($board, 'Too deep already', parent: $nested); // saved before the limit
        $source = $this->reloaded($source);
        $copier = self::getContainer()->get(TaskCopier::class);
        self::assertInstanceOf(TaskCopier::class, $copier);

        try {
            $copier->copy($source, new TaskCopyOptions(), $this->owner);
            self::fail('Refused expected.');
        } catch (TaskCopyRefusedException $refused) {
            self::assertSame(['too_deep', ['%max%' => 1]], [$refused->reason, $refused->params]);
        }
        $this->expectException(TaskCopyRefusedException::class);
        $copier->preview($source, new TaskCopyOptions());
    }

    public function testACopyWithinTheLimitIsMade(): void
    {
        $board  = $this->board();
        $source = $this->task($board, 'Source');
        $this->task($board, 'Its child', parent: $source);
        $source = $this->reloaded($source);
        $copier = self::getContainer()->get(TaskCopier::class);
        self::assertInstanceOf(TaskCopier::class, $copier);

        self::assertCount(2, $copier->copy($source, new TaskCopyOptions(), $this->owner)->tasks);
    }

    public function testImportedRowsGoUnderTheirDeepestAllowedAncestor(): void
    {
        $board  = $this->board();
        $import = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $import);

        $result = $import->import($board, TaskImportSource::TaskBoardCsv, "id,parent,title\na,,Root\nb,a,Child\nc,b,Grandchild\n", 'plan.csv', $this->owner);

        self::assertSame(3, $result->created);
        $this->em->clear();
        $board = $this->em->find($board::class, $board->getId());
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertNotNull($board);
        self::assertNotNull($owner);
        $this->owner = $owner;
        $grandchild  = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Grandchild']);
        self::assertInstanceOf(Task::class, $grandchild);
        self::assertSame('Root', $grandchild->getParent()?->getTitle());
        self::assertNotSame([], array_filter($result->warnings, static fn (string $warning): bool => str_contains($warning, 'deeper than 1 levels of subtasks; task created under "Root"')));

        // A matched row whose parent would take it (and its subtree) past the limit keeps its parent.
        $again = $import->import($board, TaskImportSource::TaskBoardCsv, "id,parent,title\na,b,Root\n", 'plan.csv', $this->owner);
        self::assertNotSame([], array_filter($again->warnings, static fn (string $warning): bool => str_contains($warning, 'would go deeper than 1 levels') || str_contains($warning, 'its own ancestor')));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE title = ?', ['Root']));

        // A matched subtree moved under a task at depth 1: its child would reach depth 3.
        $import->import($board, TaskImportSource::TaskBoardCsv, "id,parent,title\nm,,Mid\nn,m,Mid child\n", 'more.csv', $this->owner);
        $deep = $import->import($board, TaskImportSource::TaskBoardCsv, "id,parent,title\nm,b,Mid\n", 'more.csv', $this->owner);
        self::assertNotSame([], array_filter($deep->warnings, static fn (string $warning): bool => str_contains($warning, 'would go deeper than 1 levels of subtasks')));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT parent_id FROM task_board_tasks WHERE title = ?', ['Mid']));
    }

    public function testImportIntoATaskAtTheLimitPlacesRowsAtTheTopLevel(): void
    {
        $board  = $this->board();
        $child  = $this->task($board, 'Child', parent: $this->task($board, 'Root'));
        $import = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $import);
        $child = $this->em->find(Task::class, $child->getId());
        self::assertInstanceOf(Task::class, $child);

        $result = $import->import($board, TaskImportSource::TaskBoardCsv, "id,parent,title\nx,,Sub\n", 'plan.csv', $this->owner, parentTask: $child);

        self::assertSame(1, $result->created);
        self::assertSame('Root', $this->em->getRepository(Task::class)->findOneBy(['title' => 'Sub'])?->getParent()?->getTitle());
    }

    private function reloaded(Task $task): Task
    {
        $this->em->clear();
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertNotNull($owner);
        $this->owner = $owner;
        $task        = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $task);

        return $task;
    }
}
