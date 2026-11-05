<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync;

use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function array_filter;
use function count;
use function str_starts_with;
use function stripos;

/**
 * Since 1.25 (§13.1, SC-002 shape): one reassign batch reads with a constant number of queries, whatever the number of
 * tasks in it (writes grow with the rows changed, reads do not).
 */
final class SyncReassignBudgetTest extends SyncReassignTestCase
{
    public function testTheReadsOfABatchDoNotGrowWithItsSize(): void
    {
        self::assertSame($this->readsFor(1), $this->readsFor(12));
    }

    private function readsFor(int $tasks): int
    {
        $alice      = $this->user('alice' . $tasks . '@example.test');
        $bob        = $this->user('bob' . $tasks . '@example.test');
        $board      = $this->board('Board ' . $tasks);
        $connection = $this->connect($board, $directory = $this->directory());
        $person     = $this->person($directory, '9001', $alice);
        for ($i = 0; $i < $tasks; ++$i) {
            $this->item($connection, 'T' . $tasks . '-' . $i, $person, $alice, $alice, $alice, [TaskMemberRole::Assignee, TaskMemberRole::Watcher]);
        }
        $this->viewer($board, $alice, $bob);
        $this->em->clear();
        $this->refreshOwner();
        $person = $this->freshPerson($person);
        $bob    = $this->em->find(AppUser::class, $bob->getId());
        self::assertInstanceOf(AppUser::class, $bob);

        $logger          = $this->logger();
        $logger->records = [];
        $report          = $this->reassigner()->reassign($person, $bob, $this->owner);
        self::assertSame($tasks, $report->tasks);

        return count(array_filter($logger->records, static fn (array $r): bool => str_starts_with($r['message'], 'Executing') && stripos((string) ($r['context']['sql'] ?? ''), 'SELECT') === 0));
    }
}
