<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use ReflectionProperty;
use RuntimeException;

/**
 * Since 3.2.0 (CU-2): a provider with the base interface only (no sources, statuses, fields or people) still imports;
 * a user who may not create boards, a board gone before the start and a failing board creation are refused cleanly.
 */
final class ImportPlanPlainProviderTest extends ImportPlanTestCase
{
    protected static function syncConfig(): array
    {
        $config                     = parent::syncConfig();
        $config['__plain_provider'] = true;
        $config['security']         = ['create_roles' => ['ROLE_ADMIN']];

        return $config;
    }

    protected static function planConfig(): array
    {
        return ['runner' => 'app.plan_runner'];
    }

    public function testAPlainProviderImportsLists(): void
    {
        $plans = $this->plans();
        $plan  = $plans->draft(null, $this->admin, 'plain');
        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:1', $this->admin);
        self::assertNull($plan->getDirectory(), 'no people');
        $plans->chooseSources($plan, $plan->getVersion(), ['L1'], ImportPlanLayout::PerList, []);
        self::assertSame([], $plans->statusGroups($plan)[0]['statuses']);
        self::assertSame([], $plans->fields($plan));
        $plans->chooseBoards($plan, $plan->getVersion(), [], $this->admin);
        $plans->mapStatuses($plan, $plan->getVersion(), []);
        $plans->chooseFields($plan, $plan->getVersion(), []);
        $plans->choosePeople($plan, $plan->getVersion(), false);
        $plans->confirmPreview($plan, $plan->getVersion(), ReimportMode::UpdateChanged);
        self::assertSame(2, $this->previewer()->preview($plan, $this->admin)->items[0]['tasks']);

        // Not allowed to create boards here: refused before anything is written.
        try {
            $plans->start($plan, $plan->getVersion(), $this->owner);
            self::fail('refused');
        } catch (ImportPlanException $exception) {
            self::assertSame(ImportPlanException::BOARD_UNAVAILABLE, $exception->reason);
        }

        // A failing board creation frees the workspace again.
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $boom       = static function (): never {
            throw new RuntimeException('host listener failed');
        };
        $dispatcher->addListener(TaskBoardEvents::BOARD_CREATED, $boom);
        try {
            $plans->start($plan, $plan->getVersion(), $this->admin);
            self::fail('board creation failed');
        } catch (RuntimeException) {
            self::assertNull($this->fresh($plan)->getLockKey());
        }
        $dispatcher->removeListener(TaskBoardEvents::BOARD_CREATED, $boom);

        $plan = $this->fresh($plan);
        $plans->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame(ImportPlanStatus::Succeeded, $this->runner()->runSlice($plan->getId(), null, 60));
        self::assertSame(2, $this->fresh($plan)->reportInt('created'));

        // An existing target gone before a re-import: the item fails at the start, the others run.
        $item = $this->itemsOf($plan)[0];
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'boardId'))->setValue($item, 'gone');
        $this->em->flush();
        $plan = $this->fresh($plan);
        $plans->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame([ImportPlanItemStatus::Failed, 'board_unavailable'], [$this->itemsOf($plan)[0]->getStatus(), $this->itemsOf($plan)[0]->getErrorCode()]);
    }
}
