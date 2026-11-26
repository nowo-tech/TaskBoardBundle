<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanRunnerInterface;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Plan\MessengerPlanRunner;
use Nowo\TaskBoardBundle\Sync\Plan\PlanPreview;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;
use Nowo\TaskBoardBundle\Sync\Provider\TimeAccessMode;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;

use function count;

/**
 * Since 3.2.0 (CU-2, Q-CU-9, Q-CU-15): the optional Messenger runner (the test bus handles messages at once), the
 * preview with a time sink, a member token, estimated counts, no shared budget and a one-board limit.
 */
final class ImportPlanMessengerTest extends ImportPlanTestCase
{
    protected static function syncConfig(): array
    {
        $config                         = parent::syncConfig();
        $config['sync']['rate_limit']   = ['enabled' => false];
        $config['sync']['time_entries'] = ['sink' => 'app.time_sink'];
        // One task page per board run: a board of more than 100 tasks takes several slices.
        $config['sync']['max_tasks_per_run'] = 1;
        // The test kernel's Messenger bus (synchronous: messages are handled at once).
        $config['__worker']   = true;
        $config['recurrence'] = ['enabled' => true];

        return $config;
    }

    protected static function planConfig(): array
    {
        return ['messenger' => ['enabled' => true, 'slice_seconds' => 60], 'preview_max_pages' => 1, 'max_items' => 1];
    }

    public function testTheQueuedRunnerImportsAndQueuesTheNextSlice(): void
    {
        self::assertInstanceOf(MessengerPlanRunner::class, self::getContainer()->get(ImportPlanRunnerInterface::class));
        for ($i = 0; $i < 101; ++$i) {
            $this->put($this->remote('m' . $i, 'Many ' . $i, 10 + $i));
        }
        $plan = $this->preparedPlan(['L1']);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame(ImportPlanStatus::Succeeded, $this->fresh($plan)->getStatus(), 'the next slices were queued until done');
        self::assertSame(103, $this->fresh($plan)->reportInt('created'));

        // The Tree step offers tracked time (the host has a sink).
        $this->login($this->admin);
        $draft = $this->plans()->draft(null, $this->admin, 'clickup');
        $this->plans()->chooseConnection($draft, 1, $this->plans()->connections($draft, $this->admin)[0]);
        $this->plans()->chooseWorkspace($draft, 2, 'w:1', $this->admin);
        $this->client->request('GET', '/tools/task-board/import-plans/' . $draft->getId() . '?step=tree');
        self::assertSelectorExists('input[name="task_board_import_plan[timeEntries]"]');

        // An outage: the next slice is queued with a delay (the test bus runs it at once: not due, nothing more).
        $plan = $this->fresh($plan);
        $this->provider->failOn('fetchTasks:L1', new ProviderException(ProviderErrorCode::Timeout));
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame([ImportPlanStatus::Paused, 'timeout'], [$this->fresh($plan)->getStatus(), $this->fresh($plan)->getErrorCode()]);
    }

    public function testThePreviewCountsWarnsAndAsksTheQuota(): void
    {
        for ($i = 0; $i < 101; ++$i) {
            $this->put($this->remote('m' . $i, 'Many ' . $i, 10 + $i));
        }
        $this->provider->withWorkspace('1', [new RemotePerson('7', 'Ada Example', 'ada@example.test')], new TimeAccess(TimeAccessMode::Member));

        // The People wait reads the workspace's people into the tenant's directory; Ada stays to decide.
        $waiting = $this->preparedPlan(['L2'], options: ['peopleFirst' => true]);
        $this->plans()->start($waiting, $waiting->getVersion(), $this->admin);
        self::assertSame('people_mapping_required', $this->fresh($waiting)->getErrorCode());

        $plan    = $this->preparedPlan(['L1'], options: [TaskSyncImportPlan::OPTION_TIME => true, TaskSyncImportPlan::OPTION_COMMENTS => false]);
        $preview = $this->previewer()->preview($plan, $this->admin);
        self::assertSame([PlanPreview::WARNING_ESTIMATED, PlanPreview::WARNING_WORKSPACE_BUSY, PlanPreview::WARNING_WORKSPACE_BEFORE, PlanPreview::WARNING_TIME_MEMBER, PlanPreview::WARNING_PEOPLE_UNREVIEWED], $preview->warnings);
        self::assertSame([200, true, 90], [$preview->items[0]['tasks'], $preview->items[0]['estimated'], $preview->perMinute]);
        self::assertSame(['mapped' => 0, 'unreviewed' => 1, 'total' => 1], $preview->people);
        self::assertSame(1, $preview->items[0]['columns'], 'Doing is not a default column (To do, In progress, Done)');

        // A provider error: shown on the item, and a time access that cannot be read warns too.
        $this->provider->failOn('fetchSourceTasks:L1', new ProviderException(ProviderErrorCode::Forbidden));
        $this->provider->failOn('timeAccess:1', new ProviderException(ProviderErrorCode::Forbidden));
        $preview = $this->previewer()->preview($plan, $this->admin);
        self::assertSame('forbidden', $preview->items[0]['error']);
        self::assertTrue($preview->hasWarning(PlanPreview::WARNING_TIME_MEMBER));

        // An existing board with other columns: its new columns are counted; the host's quota answers.
        $board = $this->board('Existing', ['Open'], $this->admin);
        $plans = $this->plans();
        $item  = $this->store()->items($plan)[0];
        $plan  = $this->fresh($plan);
        $plans->chooseBoards($plan, $plan->getVersion(), [$item->getId() => ['target' => $board->getId(), 'name' => '']], $this->admin);
        $plans->mapStatuses($plan, $plan->getVersion(), []);
        self::assertSame(StatusColumns::CREATE, $this->store()->items($plan)[0]->getStatusMap()[0]['column']);
        self::assertSame(3, $this->previewer()->preview($plan, $this->admin)->items[0]['columns']);
        $plan2                                                = $this->preparedPlan(['L2']);
        self::getContainer()->get('app.quota_listener')->deny = ['board_create'];
        self::assertSame('Your plan is full.', $this->previewer()->preview($plan2, $this->admin)->refusal);

        // One board at most here.
        $draft = $plans->draft(null, $this->admin, 'clickup');
        $plans->chooseConnection($draft, 1, $plans->connections($draft, $this->admin)[0]);
        $plans->chooseWorkspace($draft, 2, 'w:1', $this->admin);
        try {
            $plans->chooseSources($draft, 3, ['s:1'], ImportPlanLayout::PerList, []);
            self::fail('too many');
        } catch (ImportPlanException $exception) {
            self::assertSame([ImportPlanException::TOO_MANY_ITEMS, '1'], [$exception->reason, $exception->detail]);
        }

        // Audit ZBYv-L1: more ids than boards are refused before any provider call (no budget spent).
        $calls = count($this->provider->calls);
        try {
            $plans->chooseSources($draft, 3, ['L1', 'L2'], ImportPlanLayout::PerList, []);
            self::fail('too many ids');
        } catch (ImportPlanException $exception) {
            self::assertSame(ImportPlanException::TOO_MANY_ITEMS, $exception->reason);
        }
        self::assertCount($calls, $this->provider->calls);
    }
}
