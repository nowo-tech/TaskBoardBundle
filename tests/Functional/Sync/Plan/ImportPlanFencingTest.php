<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TenantAccessCheckEvent;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Event\ImportPlanEvent;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingPlanRunner;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * Since 3.2.0 (audit ZBY-L2, REQ-RUNTIME-003): a slice that was taken over writes nothing more and never frees the
 * workspace lock; cancel and resume are compare-and-sets on the status; a wizard step is one transaction; and, with a
 * second database connection (MySQL, MariaDB, PostgreSQL), the claim, the workspace lock and cancel against a claim.
 */
final class ImportPlanFencingTest extends ImportPlanTestCase
{
    protected static function planConfig(): array
    {
        return ['runner' => 'app.plan_runner'];
    }

    public function testATakenOverSliceStopsWritingAndKeepsTheLock(): void
    {
        $plan = $this->started(['L1', 'L2']);
        // Another worker takes the plan over while the first board's run ends.
        $takeover = function (): void {
            $this->sql("UPDATE task_board_sync_import_plans SET run_token = 'other-worker', heartbeat_at = ?", [(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        };
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_FINISHED, $takeover);
        self::assertSame(ImportPlanStatus::Running, $this->runner()->runSlice($plan->getId(), 60));
        $this->dispatcher()->removeListener(TaskBoardEvents::SYNC_FINISHED, $takeover);

        $plan = $this->fresh($plan);
        self::assertSame(['other-worker', ImportPlanStatus::Running], [$plan->getRunToken(), $plan->getStatus()]);
        self::assertNotNull($plan->getLockKey(), 'the stale slice did not free the workspace');
        self::assertSame(ImportPlanItemStatus::Pending, $this->itemsOf($plan)[1]->getStatus(), 'nor run the next board');
        self::assertNull($this->runner()->runSlice($plan->getId(), 60), 'the live worker holds it');

        // A token that changes between two items: the slice stops before the next one.
        $this->sql("UPDATE task_board_sync_import_plans SET status = 'queued', run_token = NULL, heartbeat_at = NULL");
        $changed = function (): void {
            $this->sql("UPDATE task_board_sync_import_plans SET run_token = 'third'");
        };
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_CONNECTION_LINKED, $changed);
        $this->runner()->runSlice($plan->getId(), 60);
        $this->dispatcher()->removeListener(TaskBoardEvents::BOARD_CONNECTION_LINKED, $changed);
        self::assertSame('third', $this->fresh($plan)->getRunToken());

        // A stale slice that would end the plan (here: its starter refused) does not: the live one decides.
        $this->sql("UPDATE task_board_sync_import_plans SET status = 'queued', run_token = NULL, heartbeat_at = NULL");
        $this->sql("UPDATE task_board_sync_import_plan_items SET status = 'pending' WHERE position = 1");
        $refuse = function (TenantAccessCheckEvent $event): void {
            $this->sql("UPDATE task_board_sync_import_plans SET run_token = 'fourth'");
            $event->deny();
        };
        $this->dispatcher()->addListener(TaskBoardEvents::TENANT_ACCESS_CHECK, $refuse);
        $this->runner()->runSlice($plan->getId(), 60);
        $this->dispatcher()->removeListener(TaskBoardEvents::TENANT_ACCESS_CHECK, $refuse);
        $plan = $this->fresh($plan);
        self::assertSame([ImportPlanStatus::Running, 'fourth', null], [$plan->getStatus(), $plan->getRunToken(), $plan->getErrorCode()]);
        self::assertNotNull($plan->getLockKey());
    }

    public function testCancelAndResumeAreConditional(): void
    {
        $plan = $this->started(['L1']);
        // A worker claimed it between the page's read and the cancel: the plan is not ended under its feet.
        $this->sql("UPDATE task_board_sync_import_plans SET status = 'running', run_token = 'w1', heartbeat_at = ?", [(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $this->plans()->cancel($plan);
        $plan = $this->fresh($plan);
        self::assertSame([ImportPlanStatus::Running, true], [$plan->getStatus(), $plan->isCancelRequested()]);
        self::assertNotNull($plan->getLockKey());

        // That slice may no longer pause: the cancel wins (audit ZBYv-L2).
        self::assertFalse($this->store()->hold($plan->getId(), 'w1', ImportPlanStatus::Paused, null, null, []));
        self::assertSame(ImportPlanStatus::Running, $this->fresh($plan)->getStatus());

        // Resume of a plan that is not held does nothing (no second runner request).
        $requests = count(RecordingPlanRunner::$requests);
        $this->plans()->resume($plan);
        self::assertCount($requests, RecordingPlanRunner::$requests);
    }

    public function testACancelDuringTheItemEndsThePlanInsteadOfAPause(): void
    {
        // Audit ZBYv-L2: the cancel arrives while the board's run is on, and the run is then rate-limited.
        $plan = $this->started(['L1', 'L2']);
        $this->provider->failOn('fetchTasks:L1', new RateLimitedException(30));
        $cancel = function () use ($plan): void {
            $this->plans()->cancel($plan);
        };
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_CONNECTION_LINKED, $cancel);
        $events = [];
        $record = static function (ImportPlanEvent $event) use (&$events): void {
            $events[] = $event->status;
        };
        $this->dispatcher()->addListener(TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED, $record);
        self::assertSame(ImportPlanStatus::Cancelled, $this->runner()->runSlice($plan->getId(), 60));
        $this->dispatcher()->removeListener(TaskBoardEvents::BOARD_CONNECTION_LINKED, $cancel);
        $this->dispatcher()->removeListener(TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED, $record);

        $plan = $this->fresh($plan);
        self::assertSame([ImportPlanStatus::Cancelled, null, null], [$plan->getStatus(), $plan->getLockKey(), $plan->getRunToken()]);
        self::assertSame(['cancelled'], $events, 'one event, from the slice that ended it');
        self::assertNull($this->store()->cancel($plan->getId(), new DateTimeImmutable()), 'nothing left to cancel');
    }

    public function testAWizardStepIsOneTransaction(): void
    {
        $existing = $this->board('Existing', ['To do'], $this->admin);
        $plans    = $this->plans();
        $plan     = $plans->draft(null, $this->admin, 'clickup');
        $plans->chooseConnection($plan, 1, $plans->connections($plan, $this->admin)[0]);
        $plans->chooseWorkspace($plan, 2, 'w:1', $this->admin);
        $plans->chooseSources($plan, 3, ['L1', 'L2'], ImportPlanLayout::PerList, []);
        [$first, $second] = $this->store()->items($plan);
        try {
            $plans->chooseBoards($plan, 4, [$first->getId() => ['target' => $existing->getId(), 'name' => ''], $second->getId() => ['target' => 'nope', 'name' => '']], $this->admin);
            self::fail('refused');
        } catch (ImportPlanException $exception) {
            self::assertSame(ImportPlanException::BOARD_UNAVAILABLE, $exception->reason);
        }
        $plan = $this->fresh($plan);
        self::assertSame(4, $plan->getVersion(), 'the version did not move');
        self::assertTrue($this->itemsOf($plan)[0]->isCreateBoard(), 'the first item was not changed');
    }

    public function testTwoConnectionsClaimLockAndCancel(): void
    {
        $other = $this->secondConnection();
        $plan  = $this->started(['L1']);

        // Connection 2 claims first: this process runs nothing.
        $other->executeStatement("UPDATE task_board_sync_import_plans SET status = 'running', run_token = 'c2', heartbeat_at = ? WHERE id = ? AND status = 'queued'", [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $plan->getId()]);
        self::assertNull($this->runner()->runSlice($plan->getId(), 60));

        // Cancel against connection 2's claim: only asked, the lock stays.
        $this->plans()->cancel($plan);
        self::assertSame('1', (string) (int) $other->fetchOne('SELECT cancel_requested FROM task_board_sync_import_plans WHERE id = ?', [$plan->getId()]));
        self::assertNotNull($other->fetchOne('SELECT lock_key FROM task_board_sync_import_plans WHERE id = ?', [$plan->getId()]));

        // A second plan of the workspace cannot start while connection 2's plan holds the lock.
        $second = $this->preparedPlan(['L2']);
        try {
            $this->plans()->start($second, $second->getVersion(), $this->admin);
            self::fail('busy');
        } catch (ImportPlanException $exception) {
            self::assertSame(ImportPlanException::WORKSPACE_BUSY, $exception->reason);
        }
        self::assertSame(ImportPlanStore::lockKey(null, 'clickup', '1'), $other->fetchOne('SELECT lock_key FROM task_board_sync_import_plans WHERE id = ?', [$plan->getId()]));

        // Connection 2's slice cannot pause past the cancel (audit ZBYv-L2) …
        self::assertSame(0, $other->executeStatement("UPDATE task_board_sync_import_plans SET status = 'paused', run_token = NULL WHERE id = ? AND run_token = 'c2' AND status = 'running' AND cancel_requested = ?", [$plan->getId(), false], [ParameterType::STRING, ParameterType::BOOLEAN]));
        // … and a plan it paused before the cancel ends at once, its workspace free.
        $other->executeStatement("UPDATE task_board_sync_import_plans SET status = 'paused', run_token = NULL, cancel_requested = ? WHERE id = ?", [false, $plan->getId()], [ParameterType::BOOLEAN, ParameterType::STRING]);
        $this->plans()->cancel($plan);
        self::assertSame(['cancelled', null], [$other->fetchOne('SELECT status FROM task_board_sync_import_plans WHERE id = ?', [$plan->getId()]), $other->fetchOne('SELECT lock_key FROM task_board_sync_import_plans WHERE id = ?', [$plan->getId()])]);
        $other->close();
    }

    private function started(array $sources): TaskSyncImportPlan
    {
        $plan = $this->preparedPlan($sources);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);

        return $this->fresh($plan);
    }

    private function secondConnection(): Connection
    {
        $main = $this->em->getConnection();
        if ($main->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('One writer at a time: there is no second connection to race with.');
        }

        return DriverManager::getConnection($main->getParams());
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
