<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskNumberScopeInUseException;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberCounter;
use Nowo\TaskBoardBundle\Number\Entity\TaskNumberEntry;
use Nowo\TaskBoardBundle\Number\TaskNumberBackfiller;
use Nowo\TaskBoardBundle\Number\TaskNumberReserver;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function microtime;
use function sprintf;

/**
 * Spec 214, block A: every creation path numbers a task once; numbers are per scope, never reused, and survive a re-save.
 */
final class TaskNumberAssignmentTest extends TaskNumberTestCase
{
    public function testTheBoardsOfATenantShareOneSequenceAndAnotherTenantHasItsOwn(): void
    {
        $one   = $this->board('One', tenantKey: 'acme');
        $two   = $this->board('Two', tenantKey: 'acme');
        $other = $this->board('Three', tenantKey: 'other');

        self::assertSame(1, $this->task($one, 'a')->getNumber());
        self::assertSame(2, $this->task($two, 'b')->getNumber());
        self::assertSame(3, $this->task($one, 'c')->getNumber());
        self::assertSame(1, $this->task($other, 'd')->getNumber());
    }

    public function testABoardWithoutATenantUsesItsOwnId(): void
    {
        $one = $this->board('One');
        $two = $this->board('Two');

        self::assertSame(1, $this->task($one, 'a')->getNumber());
        self::assertSame(1, $this->task($two, 'b')->getNumber());
        self::assertSame($this->task($one, 'c'), $this->ledger()->find($one->getId(), 2));
    }

    public function testScopeBoardGivesEachBoardItsOwnSequenceInsideATenant(): void
    {
        $this->reconfigure(['task_numbers' => ['enabled' => true, 'scope' => 'board']]);
        $one = $this->board('One', tenantKey: 'acme');
        $two = $this->board('Two', tenantKey: 'acme');

        self::assertSame(1, $this->task($one, 'a')->getNumber());
        self::assertSame(1, $this->task($two, 'b')->getNumber());
    }

    public function testEveryTaskOfOneFlushGetsANumberInWriteOrderSubtasksToo(): void
    {
        $board  = $this->board('One', tenantKey: 'acme');
        $parent = new Task(board: $board, title: 'parent', creator: $this->owner);
        $child  = new Task(board: $board, title: 'child', creator: $this->owner, parent: $parent);
        $last   = new Task(board: $board, title: 'last', creator: $this->owner);
        $this->em->persist($parent);
        $this->em->persist($child);
        $this->em->persist($last);
        $this->em->flush();

        self::assertSame([1, 2, 3], [$parent->getNumber(), $child->getNumber(), $last->getNumber()]);
        self::assertSame($child, $this->ledger()->find('acme', 2));
    }

    public function testASaveLeavesTheNumberAlone(): void
    {
        $task = $this->task($this->board('One', tenantKey: 'acme'), 'a');
        $task->setTitle('Renamed');
        $this->em->flush();

        self::assertSame(1, $task->getNumber());
        self::assertCount(1, $this->em->getRepository(TaskNumberEntry::class)->findAll());
    }

    /** SC-002, FR-004. */
    public function testADeletedTasksNumberIsRetired(): void
    {
        $this->enforceForeignKeys();
        $board = $this->board('One', tenantKey: 'acme');
        $this->task($board, 'a');
        $last = $this->task($board, 'b');
        $this->em->remove($last);
        $this->em->flush();
        $this->clear();

        self::assertNull($this->ledger()->find('acme', 2));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE scope_key = ? AND number = 2 AND task_id IS NULL', TaskNumberEntry::TABLE), ['acme']));

        $board = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertSame(3, $this->task($board, 'c')->getNumber());
    }

    /** A task created and deleted in one process: the ledger row must not stop the next flush. */
    public function testDeletingATaskCreatedInThisProcessLeavesTheNextFlushWorking(): void
    {
        $board = $this->board('One', tenantKey: 'acme');
        $task  = $this->task($board, 'a');
        $this->em->remove($task);
        $this->em->flush();

        self::assertSame(2, $this->task($board, 'b')->getNumber());
    }

    /** FR-004: emptying or deleting a board keeps the numbers of its tenant. */
    public function testErasingABoardKeepsItsNumbersRetired(): void
    {
        $this->enforceForeignKeys();
        $board = $this->board('One', tenantKey: 'acme');
        $other = $this->board('Two', tenantKey: 'acme');
        $this->task($board, 'a');
        $this->task($board, 'b');
        $eraser = self::getContainer()->get('app.board_eraser');
        self::assertInstanceOf(TaskBoardEraser::class, $eraser);
        do {
            $progress = $eraser->deleteBoard($board, $this->owner);
        } while (!$progress->done);
        $this->clear();

        self::assertNull($this->ledger()->find('acme', 1));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE scope_key = ? AND task_id IS NULL', TaskNumberEntry::TABLE), ['acme']));

        $other = $this->em->find(TaskBoard::class, $other->getId());
        self::assertInstanceOf(TaskBoard::class, $other);
        self::assertSame(3, $this->task($other, 'c')->getNumber());
    }

    /** FR-006: another tenant gives a new number, and the old one stays an alias. */
    public function testAMoveToAnotherTenantGetsANewNumberAndKeepsTheOldAsAnAlias(): void
    {
        $from = $this->board('One', tenantKey: 'acme');
        $to   = $this->board('Two', tenantKey: 'other');
        $this->task($to, 'there');
        $task = $this->task($from, 'moved');
        self::assertSame(1, $task->getNumber());

        $this->moveTo($task, $to);
        $this->em->flush();

        self::assertSame(2, $task->getNumber());
        self::assertSame($task, $this->ledger()->find('acme', 1));
        self::assertSame($task, $this->ledger()->find('other', 2));
    }

    public function testAMoveInsideATenantKeepsTheNumber(): void
    {
        $from = $this->board('One', tenantKey: 'acme');
        $to   = $this->board('Two', tenantKey: 'acme');
        $task = $this->task($from, 'moved');

        $this->moveTo($task, $to);
        $this->em->flush();

        self::assertSame(1, $task->getNumber());
        self::assertCount(1, $this->em->getRepository(TaskNumberEntry::class)->findAll());
    }

    public function testFindInScopesAnswersOnlyTheGivenScopes(): void
    {
        $acme  = $this->task($this->board('One', tenantKey: 'acme'), 'a');
        $other = $this->task($this->board('Two', tenantKey: 'other'), 'b');
        $this->task($this->board('Three', tenantKey: 'hidden'), 'c');

        self::assertEqualsCanonicalizing([$acme->getId(), $other->getId()], array_map(static fn (Task $task): string => $task->getId(), $this->ledger()->findInScopes(['acme', 'other'], 1)));
        self::assertSame([], $this->ledger()->findInScopes([], 1));
    }

    /** FR-005: what the host calls when a tenant is destroyed. */
    public function testPurgeScopeForgetsOnlyThatScope(): void
    {
        $gone = $this->task($this->board('One', tenantKey: 'acme'), 'a');
        $this->task($this->board('Two', tenantKey: 'other'), 'b');
        $this->em->remove($gone);
        $this->em->flush();

        $this->ledger()->purgeScope('acme');

        $db = $this->em->getConnection();
        self::assertSame(0, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE scope_key = ?', TaskNumberEntry::TABLE), ['acme']));
        self::assertSame(0, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), ['acme']));
        self::assertSame(1, (int) $db->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE scope_key = ?', TaskNumberEntry::TABLE), ['other']));
    }

    /** Audit HY-L2: with a task left, a new one would share its number. */
    public function testPurgeScopeRefusesWhileATaskCarriesANumberThere(): void
    {
        $board = $this->board('One', tenantKey: 'acme');
        $task  = $this->task($board, 'a');

        try {
            $this->ledger()->purgeScope('acme');
            self::fail('The purge should have been refused.');
        } catch (TaskNumberScopeInUseException $exception) {
            self::assertStringContainsString('acme', $exception->getMessage());
        }
        self::assertSame($task, $this->ledger()->find('acme', 1));
        self::assertSame(2, $this->task($board, 'b')->getNumber());
    }

    public function testPurgeScopeRefusesForABoardWithoutATenant(): void
    {
        $board = $this->board('One');
        $this->task($board, 'a');

        $this->expectException(TaskNumberScopeInUseException::class);
        $this->ledger()->purgeScope($board->getId());
    }

    /** A task moved away keeps only an alias here: the scope it left can go. */
    public function testPurgeScopeAllowsAnAliasOfATaskMovedAway(): void
    {
        $to   = $this->board('Two', tenantKey: 'other');
        $task = $this->task($this->board('One', tenantKey: 'acme'), 'moved');
        $this->moveTo($task, $to);
        $this->em->flush();

        $this->ledger()->purgeScope('acme');

        self::assertNull($this->ledger()->find('acme', 1));
        self::assertSame($task, $this->ledger()->find('other', 1));
    }

    /** FR-003: a range reserved and never written is a gap, not a duplicate. */
    public function testAFailedWriteLeavesAGap(): void
    {
        $board    = $this->board('One', tenantKey: 'acme');
        $reserver = new TaskNumberReserver();
        self::assertSame(['acme' => 1], $reserver->reserve($this->em->getConnection(), ['acme' => 3]));

        self::assertSame(4, $this->task($board, 'a')->getNumber());
    }

    /** FR-003: inside a caller's transaction the counter commits on its own, so it never waits for the caller. */
    public function testAReservationInsideATransactionDoesNotWaitForIt(): void
    {
        $db = $this->em->getConnection();
        if (str_contains($db->getDatabasePlatform()::class, 'SQLite')) {
            self::markTestSkipped('SQLite allows one writer: the counter joins the transaction there.');
        }

        $reserver = new TaskNumberReserver();
        $db->beginTransaction();
        self::assertSame(['acme' => 1], $reserver->reserve($db, ['acme' => 2]));
        $db->rollBack();

        self::assertSame(2, (int) $db->fetchOne(sprintf('SELECT last_number FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), ['acme']));
    }

    /** A caller holding the counter itself (a fixture purge) must not wait for itself until the database gives up. */
    public function testACallerHoldingTheCounterIsNotBlockedByItself(): void
    {
        $db = $this->em->getConnection();
        if (str_contains($db->getDatabasePlatform()::class, 'SQLite')) {
            self::markTestSkipped('SQLite allows one writer: the counter joins the transaction there.');
        }

        $reserver = new TaskNumberReserver();
        $reserver->reserve($db, ['acme' => 1]);
        $db->beginTransaction();
        $db->executeStatement(sprintf('UPDATE %s SET last_number = last_number WHERE scope_key = ?', TaskNumberCounter::TABLE), ['acme']);

        $started = microtime(true);
        self::assertSame(['acme' => 2], $reserver->reserve($db, ['acme' => 1]));
        self::assertLessThan(10, microtime(true) - $started);
        $db->commit();
    }

    /** FR-007, SC-003: per tenant in creation order, resumable, and a no-op afterwards. */
    public function testTheBackfillNumbersOlderTasksInCreationOrder(): void
    {
        $acme  = $this->board('One', tenantKey: 'acme');
        $other = $this->board('Two', tenantKey: 'other');
        $noKey = $this->board('Three');
        $db    = $this->em->getConnection();
        $table = $this->em->getClassMetadata(Task::class)->getTableName();
        $ids   = [];
        foreach ([[$acme, '2026-03-01'], [$acme, '2026-01-01'], [$other, '2026-02-01'], [$noKey, '2026-02-02'], [$acme, '2026-02-01']] as $index => [$board, $created]) {
            $task  = $this->task($board, 'task ' . $index);
            $ids[] = $task->getId();
            $db->executeStatement(sprintf('UPDATE %s SET created_at = ?, number = NULL WHERE id = ?', $table), [$created . ' 00:00:00', $task->getId()]);
        }
        $db->executeStatement('DELETE FROM ' . TaskNumberEntry::TABLE);
        $db->executeStatement('DELETE FROM ' . TaskNumberCounter::TABLE);
        $this->clear();

        $backfiller = self::getContainer()->get(TaskNumberBackfiller::class);
        self::assertInstanceOf(TaskNumberBackfiller::class, $backfiller);
        self::assertSame(5, $backfiller->remaining());
        // Two batches of two and one: resuming keeps the order.
        self::assertSame(2, $backfiller->runBatch(2));
        self::assertSame(3, $backfiller->remaining());

        $application = new Application(self::$kernel);
        $tester      = new CommandTester($application->find('nowo:task-board:numbers-backfill'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('3 tasks numbered, 0 left', $tester->getDisplay());

        $numberOf = fn (string $id): ?int => $this->em->find(Task::class, $id)?->getNumber();
        $this->clear();
        self::assertSame([3, 1, 1, 1, 2], array_map($numberOf, $ids));
        self::assertSame($ids[1], $this->ledger()->find('acme', 1)?->getId());
        self::assertSame(3, (int) $db->fetchOne(sprintf('SELECT last_number FROM %s WHERE scope_key = ?', TaskNumberCounter::TABLE), ['acme']));

        // A no-op afterwards, and new tasks go on from the counter.
        self::assertSame(0, $backfiller->runBatch(10));
        $this->clear();
        self::assertSame(0, $tester->execute(['--batch' => '1', '--max-batches' => '1']));
        $this->clear();
        $board = $this->em->find(TaskBoard::class, $acme->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertSame(4, $this->task($board, 'new')->getNumber());
    }

    private function clear(): void
    {
        $this->em->clear();
        $owner = $this->em->find(AppUser::class, $this->owner->getId());
        self::assertInstanceOf(AppUser::class, $owner);
        $this->owner = $owner;
    }

    private function moveTo(Task $task, TaskBoard $board): void
    {
        // Spec 217 adds the move; the listener only looks at the changed board.
        (new ReflectionProperty(Task::class, 'board'))->setValue($task, $board);
    }
}
