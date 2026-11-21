<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\HostPorts;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\DeadlockException;
use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\BoardCriteria;
use Nowo\TaskBoardBundle\Dto\TaskCriteria;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Service\TaskBoardQuery;
use Nowo\TaskBoardBundle\Service\TaskBoardQueryInterface;
use Nowo\TaskBoardBundle\Service\TaskCommands;
use Nowo\TaskBoardBundle\Service\TaskCommandsInterface;
use Nowo\TaskBoardBundle\Service\TaskFlowStats;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\Tests\Functional\Move\MoveLockFailureTest;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Nowo\TaskBoardBundle\ValueObject\UserRef;
use Nowo\TransactionContract\TransientStorageFailure;

use function array_fill;
use function array_map;
use function range;

/**
 * Since 2.29.0: the host's limits and policies, a lock conflict inside a write unit, a stale loaded copy, and the
 * batches of the scans, through the id-based host ports.
 */
final class HostPortsLimitsTest extends FunctionalTestCase
{
    private TaskCommandsInterface $commands;

    private UserRef $ownerRef;

    protected function setUp(): void
    {
        self::$taskBoardConfig = SyncFunctionalTestCase::SYNC_CONFIG + ['column_transition_policy' => 'app.transition_policy'];
        parent::setUp();
        TestSyncLimitChecker::reset();
        ScriptedTransitionPolicy::$asked     = [];
        ScriptedTransitionPolicy::$meanwhile = null;
        $this->commands                      = self::getContainer()->get(TaskCommandsInterface::class);
        $this->ownerRef                      = UserRef::ofId((string) $this->owner->getId());
    }

    protected function tearDown(): void
    {
        TestSyncLimitChecker::reset();
        ScriptedTransitionPolicy::$meanwhile = null;
        parent::tearDown();
    }

    public function testTheActiveTaskLimitAndTheTransitionPolicyRefuseBeforeAnythingIsKept(): void
    {
        $board                             = $this->board('Limited', ['To do', 'Blocked', 'Done']);
        TestSyncLimitChecker::$refuseTasks = true;
        try {
            $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Over the limit'));
            self::fail('The limit refuses.');
        } catch (TaskReopenRefusedException $refused) {
            self::assertSame('active_tasks', $refused->refusal->code);
        }
        TestSyncLimitChecker::$refuseTasks = false;

        try {
            $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Blocked', columnId: $board->getColumns()->get(1)?->getId()));
            self::fail('The policy refuses the column.');
        } catch (ColumnTransitionRefusedException) {
        }
        self::assertSame(0, self::getContainer()->get(TaskBoardQueryInterface::class)->countTasks(TaskCriteria::forBoards([$board->getId()])));
    }

    public function testALockConflictInsideTheUnitIsATransientFailure(): void
    {
        $board                               = $this->board();
        $task                                = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Raced'));
        ScriptedTransitionPolicy::$meanwhile = static function (): never {
            throw new DeadlockException(MoveLockFailureTest::driverException('Deadlock found when trying to get lock', '40001', 1213), null);
        };
        try {
            $this->commands->moveTask($this->ownerRef, $task->id, $board->getColumns()->get(2)?->getId(), 0);
            self::fail('A deadlock is transient.');
        } catch (TransientStorageFailure $failure) {
            self::assertInstanceOf(DeadlockException::class, $failure->getPrevious());
        }
        ScriptedTransitionPolicy::$meanwhile = null;
        self::assertSame($board->getColumns()->get(0)?->getId(), self::getContainer()->get(TaskBoardQueryInterface::class)->findTaskById($task->id)?->columnId);
    }

    public function testAStaleLoadedCopyIsReloadedBeforeTheWrite(): void
    {
        $board = $this->board();
        $task  = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Loaded'));
        // Another writer committed meanwhile: the manager still holds revision 1.
        $this->em->getConnection()->executeStatement('UPDATE task_board_tasks SET title = ?, revision = revision + 1 WHERE id = ?', ['Theirs', $task->id]);

        $view = $this->commands->updateTask($this->ownerRef, $task->id, new TaskFormData('Mine'));
        self::assertSame(['Mine', $task->revision + 2], [$view->title, $view->revision]);
    }

    public function testScansCrossTheirBatches(): void
    {
        $board = $this->board('Batches', tenantKey: 'acme');
        foreach (['A', 'B', 'C'] as $title) {
            $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData($title, tags: ['t']));
        }
        $em    = $this->em;
        $query = new TaskBoardQuery($em, new TaskBoardUsers(), 2);
        self::assertSame(['t' => 3], $query->tagCounts(['acme']));
        $flow = (new TaskFlowStats($em, 2))->dailyFlow(['acme'], new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'));
        self::assertSame(3, $flow[0]->created);

        $page = $query->findTasks(TaskCriteria::forTenants(['acme'])->page(0, 2));
        self::assertTrue($page->hasMore());
        self::assertFalse($query->findBoards(BoardCriteria::forTenants(['acme']))->items[0]->isArchived());
        $this->expectException(InvalidArgumentException::class);
        $query->countActiveBoards(array_map(static fn (int $i): string => 'k' . $i, range(1, 501)));
    }

    public function testSnapshotErasureCrossesItsBatches(): void
    {
        $board = $this->board();
        $task  = $this->commands->createTask($this->ownerRef, $board->getId(), new TaskFormData('Many entries'));
        foreach (array_fill(0, TaskCommands::BATCH + 1, 1) as $minutes) {
            $this->commands->logTime($this->ownerRef, $task->id, $minutes, new DateTimeImmutable('2026-11-01'));
        }
        $this->commands->deleteTask($this->ownerRef, $task->id);

        self::assertSame(TaskCommands::BATCH + 1, $this->commands->clearTimeEntrySnapshotsForUser($this->ownerRef->id));
    }
}
