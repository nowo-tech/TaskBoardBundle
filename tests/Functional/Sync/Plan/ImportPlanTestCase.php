<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Field\SyncFieldType;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanPreviewer;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanRunner;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanService;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingPlanRunner;
use Nowo\TaskBoardBundle\Tests\Functional\Sync\SyncFunctionalTestCase;

/**
 * Since 3.2.0 (CU-2): the sync kernel with import plans on and a small fake workspace — space "Space 1" with the list
 * "Backlog" (L1) and the folder "Roadmap" (f:1) holding the list "Bugs" (L2). Synthetic data only.
 */
abstract class ImportPlanTestCase extends SyncFunctionalTestCase
{
    protected AppUser $admin;

    /**
     * @return array<string, mixed>
     */
    protected static function syncConfig(): array
    {
        $config                  = parent::syncConfig();
        $config['sync']['plans'] = ['enabled' => true] + static::planConfig();

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function planConfig(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        RecordingPlanRunner::reset();
        parent::setUp();
        $this->admin = $this->user('admin@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->provider
            ->withWorkspace('1', [new RemotePerson('7', 'Ada Example', 'ada@example.test')])
            ->addContainer(new RemoteContainer('f:1', 'Roadmap', ContainerType::Folder, 's:1', false, true, null, ['Space 1']))
            ->addContainer(new RemoteContainer('L2', 'Bugs', ContainerType::List, 'f:1', true, false, 1, ['Space 1', 'Roadmap']))
            ->withStatuses(self::LIST, [new RemoteStatus('To do', RemoteStatusType::Open), new RemoteStatus('Doing', RemoteStatusType::Custom), new RemoteStatus('Done', RemoteStatusType::Closed)])
            ->withFieldDefinitions(self::LIST, [new RemoteCustomField('fld1', 'Size', SyncFieldType::Text, 'short_text'), new RemoteCustomField('fld2', 'Team', SyncFieldType::Text, 'short_text')]);
        $this->put($this->remote('p1', 'Plan the release', 1), $this->remote('p2', 'Write notes', 2, 'Done'));
        $this->provider->putTask('L2', $this->remote('b1', 'Fix the login', 3, 'Doing'));
    }

    protected function plans(): ImportPlanService
    {
        $service = static::getContainer()->get(ImportPlanService::class);
        self::assertInstanceOf(ImportPlanService::class, $service);

        return $service;
    }

    protected function runner(): ImportPlanRunner
    {
        $service = static::getContainer()->get(ImportPlanRunner::class);
        self::assertInstanceOf(ImportPlanRunner::class, $service);

        return $service;
    }

    protected function previewer(): ImportPlanPreviewer
    {
        $service = static::getContainer()->get(ImportPlanPreviewer::class);
        self::assertInstanceOf(ImportPlanPreviewer::class, $service);

        return $service;
    }

    protected function store(): ImportPlanStore
    {
        $service = static::getContainer()->get(ImportPlanStore::class);
        self::assertInstanceOf(ImportPlanStore::class, $service);

        return $service;
    }

    /**
     * A draft filled up to the preview by the service (the HTTP steps are covered by the flow test).
     *
     * @param list<string> $sources
     * @param array<string, bool> $options
     */
    protected function preparedPlan(array $sources = ['s:1'], ImportPlanLayout $layout = ImportPlanLayout::PerList, array $options = [], ?string $tenantKey = null): TaskSyncImportPlan
    {
        $plans = $this->plans();
        $plan  = $plans->draft($tenantKey, $this->admin, 'clickup');
        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:1', $this->admin);
        $plans->chooseSources($plan, $plan->getVersion(), $sources, $layout, $options);
        $plans->chooseBoards($plan, $plan->getVersion(), [], $this->admin);
        $plans->mapStatuses($plan, $plan->getVersion(), []);
        $plans->chooseFields($plan, $plan->getVersion(), []);
        $plans->choosePeople($plan, $plan->getVersion(), $options['peopleFirst'] ?? false);
        $plans->confirmPreview($plan, $plan->getVersion(), ReimportMode::UpdateChanged);

        return $plan;
    }

    protected function fresh(TaskSyncImportPlan $plan): TaskSyncImportPlan
    {
        $this->em->clear();
        $this->refreshOwner();
        $fresh = $this->store()->find($plan->getId());
        self::assertInstanceOf(TaskSyncImportPlan::class, $fresh);

        return $fresh;
    }

    /**
     * @return list<TaskSyncImportPlanItem>
     */
    protected function itemsOf(TaskSyncImportPlan $plan): array
    {
        return $this->store()->items($this->fresh($plan));
    }

    /**
     * A write behind the services' back (what another process or a stale worker would do).
     *
     * @param list<mixed> $params
     */
    protected function sql(string $statement, array $params = []): int
    {
        return (int) $this->em->getConnection()->executeStatement($statement, $params);
    }

    protected function boardNamed(string $name): TaskBoard
    {
        $board = $this->em->getRepository(TaskBoard::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(TaskBoard::class, $board, $name);

        return $board;
    }
}
