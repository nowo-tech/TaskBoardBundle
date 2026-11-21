<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Dto\TaskCriteria;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Service\TaskFlowStatsInterface;
use Nowo\TaskBoardBundle\Tests\Functional\FoldsKeyColumns;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\ValueObject\UserRef;

use function array_map;

/**
 * Since 2.29.0 (house rule: tenant keys never match by collation): with the key columns on a folding collation (SQLite
 * NOCASE, MySQL / MariaDB `utf8mb4_general_ci`) the database hands back `ACME`'s rows for `acme`; the query and stats
 * ports keep only the rows whose key is equal byte for byte.
 */
final class HostPortsLookAlikeTest extends FunctionalTestCase
{
    use FoldsKeyColumns;

    public function testLookAlikeTenantsAreDropped(): void
    {
        $this->foldKeyColumns($this->em);
        $this->owner = $this->user('owner@example.com');
        $commands    = self::getContainer()->get(TaskCommandsInterface::class);
        $query       = self::getContainer()->get(TaskBoardQueryInterface::class);
        $stats       = self::getContainer()->get(TaskFlowStatsInterface::class);
        $owner       = UserRef::ofId((string) $this->owner->getId());
        $mine        = $this->board('Mine', tenantKey: 'acme');
        $theirs      = $this->board('Theirs', tenantKey: 'ACME');
        $task        = $commands->createTask($owner, $mine->getId(), new TaskFormData('Mine', tags: ['a']));
        $commands->createTask($owner, $theirs->getId(), new TaskFormData('Theirs', tags: ['b']));
        $commands->completeTask($owner, $task->id);
        $commands->completeTask($owner, $commands->createTask($owner, $theirs->getId(), new TaskFormData('Theirs done'))->id);

        self::assertSame(['Mine'], array_map(static fn ($b): string => $b->name, $query->findBoards(BoardCriteria::forTenants(['acme']))->items));
        self::assertSame(1, $query->findBoards(BoardCriteria::forTenants(['acme']))->total);
        self::assertSame(['acme' => 1], $query->countActiveBoards(['acme']));
        self::assertSame(['acme' => 0], $query->countOpenTasks(['acme']));
        self::assertSame(['a' => 1], $query->tagCounts(['acme'], false));
        self::assertSame(['Mine'], array_map(static fn ($t): string => $t->title, $query->findTasks(TaskCriteria::forTenants(['acme']))->items));
        self::assertSame(1, $query->countTasks(TaskCriteria::forTenants(['acme'])));

        $from = new DateTimeImmutable('-1 day');
        $to   = new DateTimeImmutable('+1 day');
        self::assertSame([[1, 1]], array_map(static fn ($d): array => [$d->created, $d->completed], $stats->dailyFlow(['acme'], $from, $to)));
        self::assertSame([$task->id], array_map(static fn ($s): string => $s->taskId, $stats->completedSamples(['acme'], $from, $to)));
        self::assertSame([$task->id], array_map(static fn ($e): string => $e->taskId, $stats->firstEntries(['acme'], 'Done', $from, $to)));
    }
}
