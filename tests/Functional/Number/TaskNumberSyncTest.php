<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use Nowo\TaskBoardBundle\Number\TaskNumberLedger;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncItem;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

use function array_map;
use function usort;

/**
 * Spec 214, FR-008, SC-004: a synced task gets its number when first written (inside the page's own transaction) and a re-sync
 * leaves it; the provider's custom id stays a separate reference.
 */
final class TaskNumberSyncTest extends SyncFunctionalTestCase
{
    protected static function syncConfig(): array
    {
        return self::SYNC_CONFIG + ['task_numbers' => ['enabled' => true]];
    }

    public function testASyncNumbersNewTasksInWriteOrderAndAReSyncLeavesThem(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('a1', 'Design'), $this->remote('a2', 'Build'));
        $this->sync($board);

        $before = $this->numbers($board->getId());
        self::assertSame([1, 2], $before);

        $this->put($this->remote('a1', 'Design again', 5), $this->remote('a2', 'Build', 5), $this->remote('a3', 'New', 5));
        $this->sync($board);
        $this->em->clear();

        self::assertSame([1, 2, 3], $this->numbers($board->getId()));
        self::assertCount(3, $this->em->getRepository(TaskNumberEntry::class)->findAll());
    }

    public function testACustomIdNeverBecomesTheNumber(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->put($this->remote('a42', 'Design'));
        $report = $this->sync($board);
        self::assertSame([], $report->errors, 'sync errors');

        $item = $this->em->getRepository(TaskSyncItem::class)->findOneBy([]);
        self::assertInstanceOf(TaskSyncItem::class, $item);
        $item->setCustomId('7777');
        $this->em->flush();
        $this->em->clear();

        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Design']);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(1, $task->getNumber());
        $ledger = self::getContainer()->get(TaskNumberLedger::class);
        self::assertInstanceOf(TaskNumberLedger::class, $ledger);
        self::assertNull($ledger->find($board->getId(), 7777));
    }

    /**
     * @return list<int|null>
     */
    private function numbers(string $boardId): array
    {
        $tasks = $this->em->getRepository(Task::class)->findBy(['board' => $boardId]);
        $list  = array_map(static fn (Task $task): ?int => $task->getNumber(), $tasks);
        usort($list, static fn (?int $a, ?int $b): int => $a <=> $b);

        return $list;
    }
}
