<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Event\ImportPlanEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Exception\RateLimitedException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Repository\SyncStore;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingPlanRunner;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestSyncLimitChecker;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_key_last;

/**
 * Since 3.2.0 (CU-2, design §9.2, §13, §14, §19): the plan runner — start creates the boards and takes the workspace
 * lock; each slice links and syncs the items one after the other; rate limits, outages, the People wait, a lost
 * connection or actor, failed items, cancel, retry and adoption after a crash.
 */
final class ImportPlanRunnerTest extends ImportPlanTestCase
{
    /** @var list<array{string, ImportPlanEvent}> */
    private array $events = [];

    protected static function planConfig(): array
    {
        return ['runner' => 'app.plan_runner'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([TaskBoardEvents::SYNC_IMPORT_PLAN_STARTED, TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED, TaskBoardEvents::SYNC_IMPORT_PLAN_FAILED] as $name) {
            $dispatcher->addListener($name, function (ImportPlanEvent $event) use ($name): void {
                $this->events[] = [$name, $event];
            });
        }
    }

    public function testStartCreatesTheBoardsAndTheSliceImportsThem(): void
    {
        $plan = $this->started(['L1', 'L2'], ['deleteUnusedColumns' => true]);
        self::assertCount(1, RecordingPlanRunner::$requests);
        self::assertSame([$plan->getId(), (string) $this->admin->getId()], [RecordingPlanRunner::$requests[0]->planId, RecordingPlanRunner::$requests[0]->actorId]);
        $plan = $this->fresh($plan);
        self::assertSame(ImportPlanStatus::Queued, $plan->getStatus());
        self::assertNotNull($plan->getLockKey());
        self::assertSame(TaskBoardEvents::SYNC_IMPORT_PLAN_STARTED, $this->events[0][0]);
        self::assertCount(2, $this->events[0][1]->boardIds);
        foreach ($this->itemsOf($plan) as $item) {
            self::assertFalse($item->isCreateBoard());
            self::assertNotNull($item->getBoardId());
        }

        self::assertNull($this->runner()->runSlice('00000000-0000-4000-8000-000000000000', null, 60));
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
        $plan = $this->fresh($plan);
        self::assertSame([3, 2, 0, null], [$plan->reportInt('created'), $plan->reportInt('items_done'), $plan->reportInt('items_failed'), $plan->getLockKey()]);
        self::assertSame(TaskBoardEvents::SYNC_IMPORT_PLAN_FINISHED, $this->events[1][0]);
        self::assertSame(['created' => 3], ['created' => $this->events[1][1]->counts['created']]);
        self::assertNull($this->runner()->runSlice($plan->getId(), null, 60), 'an ended plan is left alone');

        // Re-import, only new tasks: the imported ones stay as they are (counted unchanged).
        $this->plans()->start($plan, $plan->getVersion(), $this->admin, ReimportMode::AddNewOnly);
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame([0, 3], [$this->fresh($plan)->reportInt('created'), $this->fresh($plan)->reportInt('unchanged')]);
    }

    public function testOneActivePlanPerTenantAndWorkspace(): void
    {
        $first  = $this->started(['L1']);
        $second = $this->preparedPlan(['L2']);
        self::assertTrue($this->previewer()->preview($second, $this->admin)->hasWarning('workspace_busy'));
        $this->refused(ImportPlanException::WORKSPACE_BUSY, fn () => $this->plans()->start($second, $second->getVersion(), $this->admin));
        $this->refused(ImportPlanException::NOT_EDITABLE, fn () => $this->plans()->start($first, $first->getVersion(), $this->admin));
        self::assertNull($this->fresh($second)->getLockKey());

        // Once the first ended, the workspace may be imported again; the preview says it was before (Q-CU-12).
        $elsewhere = $this->preparedPlan(['L2']);
        $this->runner()->runSlice($first->getId(), null, 60);
        self::assertTrue($this->previewer()->preview($elsewhere, $this->admin)->hasWarning('workspace_imported_before'));
        $this->plans()->start($elsewhere, $this->fresh($elsewhere)->getVersion(), $this->admin);
        self::assertSame(ImportPlanStatus::Queued, $this->fresh($elsewhere)->getStatus());
    }

    public function testStartRefusals(): void
    {
        $draft = $this->plans()->draft(null, $this->admin, 'clickup');
        $this->refused(ImportPlanException::NOT_EDITABLE, fn () => $this->plans()->start($draft, 1, $this->admin));

        $plan = $this->preparedPlan(['L1']);
        $this->refused(ImportPlanException::STALE, fn () => $this->plans()->start($plan, 99, $this->admin));
        $quota       = self::getContainer()->get('app.quota_listener');
        $quota->deny = ['board_create'];
        $this->refused(ImportPlanException::QUOTA, fn () => $this->plans()->start($plan, $plan->getVersion(), $this->admin));
        $quota->deny                       = [];
        TestCredentialResolver::$available = false;
        $this->refused(ImportPlanException::CREDENTIAL, fn () => $this->plans()->start($plan, $plan->getVersion(), $this->admin));
        TestCredentialResolver::$available = true;
        self::assertSame([], RecordingPlanRunner::$requests);
    }

    public function testRateLimitOutageAndResume(): void
    {
        $plan = $this->started(['L1']);
        $this->provider->failOn('fetchTasks:L1', new RateLimitedException(30));
        self::assertSame(ImportPlanStatus::RateLimited, $this->runner()->runSlice($plan->getId(), null, 60));
        $plan = $this->fresh($plan);
        self::assertGreaterThanOrEqual((new DateTimeImmutable('+29 seconds'))->getTimestamp(), $plan->getResumeAt()?->getTimestamp());
        self::assertNull($this->runner()->runSlice($plan->getId(), null, 60), 'not due yet');

        $this->plans()->resume($plan, $this->admin);
        self::assertCount(2, RecordingPlanRunner::$requests);
        $this->provider->failOn('fetchTasks:L1', new ProviderException(ProviderErrorCode::ServerError));
        self::assertSame(ImportPlanStatus::Paused, $this->runner()->runSlice($plan->getId(), null, 60));
        $plan = $this->fresh($plan);
        self::assertSame(['server_error', 1], [$plan->getErrorCode(), $plan->reportInt('transient_retries')]);

        // Every retry spent: the item fails, the plan goes on (here: ends failed, its only item failed).
        $plan->setReport(['transient_retries' => 5] + $plan->getReport());
        $plan->hold(ImportPlanStatus::Paused, new DateTimeImmutable('-1 second'));
        $this->em->flush();
        $this->provider->failOn('fetchTasks:L1', new ProviderException(ProviderErrorCode::ServerError));
        self::assertSame(ImportPlanStatus::Failed, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame('items_failed', $this->fresh($plan)->getErrorCode());
        self::assertSame(TaskBoardEvents::SYNC_IMPORT_PLAN_FAILED, $this->events[array_key_last($this->events)][0]);

        // Retry the failed lists.
        $plan = $this->fresh($plan);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin, null, true);
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
        $this->plans()->resume($this->fresh($plan), $this->admin);
    }

    public function testItemFailuresDoNotStopThePlan(): void
    {
        $plan = $this->started(['L1', 'L2']);
        $this->provider->failOn('findSource:L1', new ProviderException(ProviderErrorCode::Forbidden));
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
        $items = $this->itemsOf($plan);
        self::assertSame([ImportPlanItemStatus::Failed, 'forbidden'], [$items[0]->getStatus(), $items[0]->getErrorCode()]);
        self::assertSame(ImportPlanItemStatus::Succeeded, $items[1]->getStatus());
        self::assertSame(1, $this->fresh($plan)->reportInt('items_failed'));

        // A source gone, a board gone, a host limit: each item fails alone.
        $plan  = $this->fresh($plan);
        $items = $this->itemsOf($plan);
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'sourceId'))->setValue($items[0], 'L9');
        $this->em->flush();
        $this->plans()->start($plan, $plan->getVersion(), $this->admin, null, true);
        $this->runner()->runSlice($plan->getId(), null, 60);
        self::assertSame('not_found', $this->itemsOf($plan)[0]->getErrorCode());

        $plan  = $this->fresh($plan);
        $items = $this->itemsOf($plan);
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'sourceId'))->setValue($items[0], 'L1');
        $this->em->flush();
        $this->plans()->start($plan, $plan->getVersion(), $this->admin, null, true);
        $items = $this->itemsOf($plan);
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'boardId'))->setValue($items[0], 'gone');
        $this->em->flush();
        $this->runner()->runSlice($plan->getId(), null, 60);
        self::assertSame('board_unavailable', $this->itemsOf($plan)[0]->getErrorCode());

        $plan                              = $this->started(['L1'], fresh: true);
        TestSyncLimitChecker::$refuseTasks = true;
        $this->runner()->runSlice($plan->getId(), null, 60);
        self::assertNotNull($this->itemsOf($plan)[0]->getErrorCode());
    }

    public function testConnectionActorAndProviderFailuresStopThePlan(): void
    {
        $plan = $this->started(['L1']);
        $this->provider->failOn('findSource:L1', new ProviderException(ProviderErrorCode::AuthFailed));
        self::assertSame(ImportPlanStatus::Failed, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame('credential', $this->fresh($plan)->getErrorCode());

        $plan = $this->fresh($plan);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        TestCredentialResolver::$available = false;
        self::assertSame(ImportPlanStatus::Failed, $this->runner()->runSlice($plan->getId(), null, 60));
        TestCredentialResolver::$available = true;

        $plan = $this->fresh($plan);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame(ImportPlanStatus::Failed, $this->runner()->runSlice($plan->getId(), 'no-such-user', 60));
        self::assertSame('actor', $this->fresh($plan)->getErrorCode());

        $plan = $this->fresh($plan);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        $plan = $this->fresh($plan);
        (new ReflectionProperty(TaskSyncImportPlan::class, 'provider'))->setValue($plan, 'gone');
        $this->em->flush();
        self::assertSame(ImportPlanStatus::Failed, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame('provider_unavailable', $this->fresh($plan)->getErrorCode());
    }

    public function testCancelDeadlineAndAnUnexpectedError(): void
    {
        $plan = $this->started(['L1', 'L2']);
        self::assertSame(ImportPlanStatus::Paused, $this->runner()->runSlice($plan->getId(), null, 0), 'no time left');

        // An unexpected error pauses the plan (outage rules); nothing is lost.
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $boom       = static function (): never {
            throw new RuntimeException('host listener failed');
        };
        $dispatcher->addListener(TaskBoardEvents::BOARD_CONNECTION_LINKED, $boom);
        $this->plans()->resume($this->fresh($plan), $this->admin);
        self::assertSame(ImportPlanStatus::Paused, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame('internal', $this->fresh($plan)->getErrorCode());
        $dispatcher->removeListener(TaskBoardEvents::BOARD_CONNECTION_LINKED, $boom);

        // The link was saved before the listener failed: the next slice adopts it (no second link).
        $this->plans()->resume($this->fresh($plan), $this->admin);
        $plan = $this->fresh($plan);
        $plan->requestCancel();
        $this->em->flush();
        self::assertSame(ImportPlanStatus::Cancelled, $this->runner()->runSlice($plan->getId(), null, 60));

        // Cancel of a waiting plan ends it at once; of an ended plan does nothing.
        $plan = $this->fresh($plan);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        $this->plans()->cancel($this->fresh($plan));
        self::assertSame(ImportPlanStatus::Cancelled, $this->fresh($plan)->getStatus());
        $this->plans()->cancel($this->fresh($plan));
        $this->refused(ImportPlanException::NOT_EDITABLE, fn () => $this->plans()->delete($this->started(['L2'], fresh: true)));
    }

    public function testAdoptsItsOwnLinkAndRefusesABoardLinkedElsewhere(): void
    {
        $plan  = $this->started(['L1']);
        $items = $this->itemsOf($plan);
        $board = $this->em->find(TaskBoard::class, $items[0]->getBoardId());
        self::assertNotNull($board);
        $sync = self::getContainer()->get(TaskBoardSyncService::class);
        $sync->link($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new RemoteContainer('L1', 'Backlog', ContainerType::List, 's:1', true, false), $this->admin);
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertNotNull($this->itemsOf($plan)[0]->getConnectionId());

        $plan  = $this->started(['L2'], fresh: true);
        $items = $this->itemsOf($plan);
        $board = $this->em->find(TaskBoard::class, $items[0]->getBoardId());
        self::assertNotNull($board);
        $sync->link($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new RemoteContainer('L1', 'Backlog', ContainerType::List, 's:1', true, false), $this->admin);
        $this->runner()->runSlice($plan->getId(), null, 60);
        self::assertSame('board_linked_elsewhere', $this->itemsOf($plan)[0]->getErrorCode());
    }

    public function testABoardBusyWithAnotherRunWaits(): void
    {
        $plan = $this->started(['L1']);
        $this->provider->failOn('fetchTasks:L1', new ProviderException(ProviderErrorCode::Timeout));
        $this->runner()->runSlice($plan->getId(), null, 60);
        $item       = $this->itemsOf($plan)[0];
        $connection = $this->em->find(TaskBoardConnection::class, (string) $item->getConnectionId());
        self::assertNotNull($connection);
        $store = self::getContainer()->get(SyncStore::class);
        $store->acquireLock($connection, 'other-run', new DateTimeImmutable(), new DateTimeImmutable('-10 minutes'));
        $this->plans()->resume($this->fresh($plan), $this->admin);
        self::assertSame(ImportPlanStatus::Paused, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame('board_busy', $this->fresh($plan)->getErrorCode());

        // Cancel asks the board's run to stop too, and ends the waiting plan.
        $this->plans()->cancel($this->fresh($plan));
        self::assertSame(ImportPlanStatus::Cancelled, $this->fresh($plan)->getStatus());
    }

    public function testThePeopleWait(): void
    {
        $plan = $this->started(['L1', 'L2'], ['peopleFirst' => true]);
        $this->provider->failOn('fetchPeople:1', new ProviderException(ProviderErrorCode::Timeout));
        self::assertSame(ImportPlanStatus::Paused, $this->runner()->runSlice($plan->getId(), null, 60));
        $plan = $this->fresh($plan);
        self::assertSame(['people_mapping_required', null, 1], [$plan->getErrorCode(), $plan->getResumeAt(), $plan->reportInt('people_waited')]);
        self::assertNull($this->runner()->runSlice($plan->getId(), null, 60), 'waits for Resume');
        $this->plans()->resume($plan, $this->admin);
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
    }

    public function testErasingAUserForgetsTheirPlansAndRetentionPrunes(): void
    {
        $plan = $this->started(['L1']);
        $this->store()->forgetUser((string) $this->admin->getId());
        $plan = $this->fresh($plan);
        self::assertSame([null, null], [$plan->getCreatedById(), $plan->getStartedById()]);

        $this->runner()->runSlice($plan->getId(), null, 60);
        $draft = $this->plans()->draft(null, $this->admin, 'clickup');
        self::assertSame(0, $this->store()->prune(new DateTimeImmutable('-1 day')));
        self::assertSame(2, $this->store()->prune(new DateTimeImmutable('+1 day')));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM task_board_sync_import_plans WHERE id = ?', [$draft->getId()]));
    }

    /**
     * @param list<string> $sources
     * @param array<string, bool> $options
     */
    private function started(array $sources, array $options = [], bool $fresh = false): TaskSyncImportPlan
    {
        if ($fresh) {
            RecordingPlanRunner::reset();
        }
        $plan = $this->preparedPlan($sources, options: $options);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);

        return $this->fresh($plan);
    }

    private function refused(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('refused: ' . $reason);
        } catch (ImportPlanException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
