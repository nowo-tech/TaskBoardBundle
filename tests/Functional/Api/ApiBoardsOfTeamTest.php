<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Api;

use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\TaskApiLevel;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\Api\Value\TaskApiBoardList;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\Api\App\ApiTestHost;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

use function array_map;
use function count;
use function fwrite;
use function sprintf;

use const STDERR;

/**
 * Since 2.22.0 (Beacon): `TaskApiReadOperations::boardsOfTeam()` reads the boards of every space of a team in one batch —
 * the spaces (one host call), the boards of their tenants with their columns (one query), the task counts (one query) —
 * and gives what `boards()` gives space by space (about three queries per space, plus the columns of each board).
 */
final class ApiBoardsOfTeamTest extends ApiTestCase
{
    /** The most queries the batch may make, whatever the number of spaces and boards. */
    private const BUDGET = 3;

    public function testTheSameQueriesForOneSpaceOrThreeAndForOneBoardOrFour(): void
    {
        $small = $this->queriesFor(['tenant-a'], 1);
        $large = $this->queriesFor(['tenant-a', 'tenant-b', 'tenant-c'], 4);

        self::assertSame($small, $large, 'no query per space or board, columns included');
        self::assertLessThanOrEqual(self::BUDGET, $large);
        fwrite(STDERR, sprintf("\n[budget] boardsOfTeam: %d queries for 1 space × 1 board, %d for 3 spaces × 4 boards\n", $small, $large));
    }

    public function testItGivesWhatBoardsGivesSpaceBySpace(): void
    {
        ApiTestHost::$tenants = ['tenant-a', 'tenant-b', null];
        $a                    = $this->board('Alpha', tenantKey: 'tenant-a');
        $b                    = $this->board('Beta', tenantKey: 'tenant-b');
        $none                 = $this->board('Loose');
        $this->board('Elsewhere', tenantKey: 'tenant-z');
        // A look-alike tenant (a folding collation's) is never another tenant's.
        $this->board('Shouting', tenantKey: 'TENANT-A');
        $archived = $this->board('Old', tenantKey: 'tenant-a');
        $archived->archive();
        $this->task($a, 'One');
        $this->task($a, 'Two');
        $this->task($none, 'Three');
        $this->em->flush();
        $this->em->clear();

        $reads  = $this->reads();
        $batch  = $reads->boardsOfTeam($this->principal(), 'team-1');
        $single = array_map(fn (string $space): TaskApiBoardList => $reads->boards($this->principal(), $space), ['space-tenant-a', 'space-tenant-b', 'space-none']);

        self::assertSame($this->shape($single), $this->shape($batch));
        self::assertSame([['space-tenant-a', ['Alpha'], [2]], ['space-tenant-b', ['Beta'], [0]], ['space-none', ['Loose'], [1]]], $this->shape($batch));
        self::assertSame(['To do', 'Doing', 'Done'], $batch[0]->boards[0]->getColumns()->map(static fn (BoardColumn $column): string => $column->getName())->getValues());
        self::assertNotSame($b->getId(), $a->getId());
    }

    public function testOnlyTheSpacesTheTokenMayListAndTheBoardsThePersonMayView(): void
    {
        ApiTestHost::$tenants            = ['tenant-a', 'tenant-b'];
        ApiTestHost::$planRefusesTenants = ['tenant-b'];
        $a                               = $this->board('Alpha', tenantKey: 'tenant-a');
        $hidden                          = $this->board('Hidden', tenantKey: 'tenant-a');
        $this->board('Beta', tenantKey: 'tenant-b');
        $this->em->clear();

        $where = TaskApiWhere::limitedTo([], [$a->getId()]);
        $lists = $this->reads()->boardsOfTeam(new TaskApiPrincipal($this->owner, TaskApiLevel::Full, $where, 'id', 'name'), 'team-1');

        self::assertSame([['space-tenant-a', ['Alpha'], [0]]], $this->shape($lists), 'the plan leaves tenant-b out; where leaves Hidden out');
        self::assertNotSame($hidden->getId(), $a->getId());
        self::assertSame([], $this->reads()->boardsOfTeam($this->principal(), 'team-2'));
    }

    public function testAnUnknownTeamIsNotFound(): void
    {
        $this->expectException(TaskApiRefusal::class);
        $this->reads()->boardsOfTeam($this->principal(), 'team-9');
    }

    /**
     * @param list<string> $tenants
     */
    private function queriesFor(array $tenants, int $boardsPerTenant): int
    {
        self::ensureKernelShutdown();
        $this->setUp();
        ApiTestHost::$tenants = $tenants;
        foreach ($tenants as $tenant) {
            for ($i = 0; $i < $boardsPerTenant; ++$i) {
                $board = $this->board('Board ' . $tenant . ' ' . $i, tenantKey: $tenant);
                $this->em->persist(new Task(board: $board, title: 'Task', creator: $this->owner, column: $board->getColumns()->get(0)));
            }
        }
        $this->em->flush();
        $this->em->clear();
        $principal = $this->principal();
        $reads     = $this->reads();

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $lists = $reads->boardsOfTeam($principal, 'team-1');
        // Reading every column of every board costs nothing more.
        $columns = 0;
        foreach ($lists as $list) {
            foreach ($list->boards as $board) {
                $columns += $board->getColumns()->count();
            }
        }
        self::assertSame(count($tenants) * $boardsPerTenant * 3, $columns);
        $count = 0;
        foreach ($holder->getData() as $queries) {
            $count += count($queries);
        }

        return $count;
    }

    private function principal(): TaskApiPrincipal
    {
        $owner = $this->em->find($this->owner::class, $this->owner->getId());
        self::assertNotNull($owner);

        return new TaskApiPrincipal($owner, TaskApiLevel::Full, TaskApiWhere::everything(), 'id', 'name');
    }

    private function reads(): TaskApiReadOperations
    {
        $reads = self::getContainer()->get(TaskApiReadOperations::class);
        self::assertInstanceOf(TaskApiReadOperations::class, $reads);

        return $reads;
    }

    /**
     * @param list<TaskApiBoardList> $lists
     *
     * @return list<array{string, list<string>, list<int>}>
     */
    private function shape(array $lists): array
    {
        return array_map(static fn (TaskApiBoardList $list): array => [
            $list->space->id,
            array_map(static fn (TaskBoard $board): string => $board->getName(), $list->boards),
            array_map(static fn (TaskBoard $board): int => $list->taskCounts[$board->getId()] ?? 0, $list->boards),
        ], $lists);
    }
}
