<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Doctrine\ORM\Events;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use RuntimeException;

use function implode;
use function range;

/**
 * Since 1.22.2 (audit EB-L7): an import is written in batches inside one transaction — the tasks of a batch leave the
 * entity manager after it is flushed (parents and relations across batches are found again), and a failure in the
 * middle keeps nothing.
 */
final class ImportBatchesTest extends FunctionalTestCase
{
    public function testALargeImportIsWrittenInBatchesWithParentsAndRelationsAcrossThem(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $rows  = ['Task ID,Task Name,Status,Parent ID,Waiting On'];
        foreach (range(1, TaskImportOrchestrator::DEFAULT_BATCH_SIZE + 10) as $i) {
            // Every row waits on the first one; the last one hangs from it (two batches later).
            $rows[] = 't' . $i . ',Task ' . $i . ',To do,' . ($i === TaskImportOrchestrator::DEFAULT_BATCH_SIZE + 10 ? 't1' : '') . ',' . ($i > 1 ? 't1' : '');
        }

        $result = $this->orchestrator()->import($board, TaskImportSource::ClickUpCsv, implode("\n", $rows), 'big.csv', $this->owner);

        self::assertFalse($result->hasErrors());
        self::assertSame(TaskImportOrchestrator::DEFAULT_BATCH_SIZE + 10, $result->created);
        self::assertSame(TaskImportOrchestrator::DEFAULT_BATCH_SIZE + 9, $result->dependenciesCreated);
        self::assertTrue($this->em->contains($board), 'the board stays managed');
        $this->em->clear();
        $last = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Task ' . (TaskImportOrchestrator::DEFAULT_BATCH_SIZE + 10)]);
        self::assertInstanceOf(Task::class, $last);
        self::assertSame('Task 1', $last->getParent()?->getTitle());

        // A second import of the same file matches every task (found again batch by batch): nothing new.
        $board = $this->em->find(TaskBoard::class, $board->getId());
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertNotNull($owner);
        $again = $this->orchestrator()->import($board, TaskImportSource::ClickUpCsv, implode("\n", $rows), 'big.csv', $owner);
        self::assertSame([0, TaskImportOrchestrator::DEFAULT_BATCH_SIZE + 10], [$again->created, $again->updated + $again->unchanged]);
    }

    public function testAFailureInTheMiddleOfTheImportKeepsNothing(): void
    {
        $board = $this->board(columns: ['To do']);
        $rows  = ['Task ID,Task Name'];
        foreach (range(1, TaskImportOrchestrator::DEFAULT_BATCH_SIZE * 2 + 1) as $i) {
            $rows[] = 'f' . $i . ',Task ' . $i;
        }
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            private int $flushes = 0;

            public function onFlush(): void
            {
                // The second batch fails after the first one was flushed.
                if (++$this->flushes === 2) {
                    throw new RuntimeException('Injected failure in the middle of the import.');
                }
            }
        });

        try {
            $this->orchestrator()->import($board, TaskImportSource::ClickUpCsv, implode("\n", $rows), 'big.csv', $this->owner);
            self::fail('the import fails');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected failure in the middle of the import.', $exception->getMessage());
        }

        $connection = $this->em->getConnection();
        self::assertFalse($connection->isTransactionActive());
        foreach (['task_board_tasks', 'task_board_task_links', 'task_board_task_change_history'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table), $table);
        }
    }

    private function orchestrator(): TaskImportOrchestrator
    {
        $orchestrator = self::getContainer()->get(TaskImportOrchestrator::class);
        self::assertInstanceOf(TaskImportOrchestrator::class, $orchestrator);

        return $orchestrator;
    }
}
