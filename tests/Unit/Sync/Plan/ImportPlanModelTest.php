<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync\Plan;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Event\TenantAccessCheckEvent;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanPhase;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStep;
use Nowo\TaskBoardBundle\Sync\Plan\PlanPreview;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

use function strlen;

/**
 * Since 3.2.0 (CU-2): the plan's states, steps, rows and value objects.
 */
final class ImportPlanModelTest extends TestCase
{
    public function testStatusesAndSteps(): void
    {
        self::assertTrue(ImportPlanStatus::RateLimited->isActive());
        self::assertFalse(ImportPlanStatus::Draft->isActive());
        self::assertTrue(ImportPlanStatus::Cancelled->isTerminal());
        self::assertFalse(ImportPlanStatus::Paused->isTerminal());
        self::assertSame(0, ImportPlanStep::Connect->position());
        self::assertSame(ImportPlanStep::Workspace, ImportPlanStep::Connect->next());
        self::assertSame(ImportPlanStep::Run, ImportPlanStep::Run->next());
        foreach (ImportPlanStep::cases() as $step) {
            self::assertSame($step !== ImportPlanStep::Run, str_contains(ImportPlanStep::REQUIREMENT, $step->value));
        }
    }

    public function testThePlanRow(): void
    {
        $now  = new DateTimeImmutable('2026-11-25 10:00');
        $plan = new TaskSyncImportPlan('acme', 'clickup', str_repeat('r', 300), 'Team', 'u1', $now);
        self::assertSame(191, strlen($plan->getConnectionRef()));
        self::assertSame(['acme', 'clickup', 'Team', 'u1', $now], [$plan->getTenantKey(), $plan->getProvider(), $plan->getConnectionLabel(), $plan->getCreatedById(), $plan->getCreatedAt()]);
        self::assertSame([ImportPlanStatus::Draft, ImportPlanLayout::PerList, ReimportMode::UpdateChanged, ImportPlanStep::Connect, 1], [$plan->getStatus(), $plan->getLayout(), $plan->getReimportMode(), $plan->getStep(), $plan->getVersion()]);

        $plan->useConnection('conn-2', 'Other');
        $plan->setWorkspace('9001', 'Acme');
        $plan->setLayout(ImportPlanLayout::Single);
        $plan->setReimportMode(ReimportMode::AddNewOnly);
        $plan->setOptions([TaskSyncImportPlan::OPTION_COMMENTS => false]);
        $plan->setExcludedFields(['f1']);
        $plan->reach(ImportPlanStep::Tree);
        $plan->reach(ImportPlanStep::Connect);
        self::assertSame(ImportPlanStep::Boards, $plan->getStep());
        $plan->rewindTo(ImportPlanStep::Workspace);
        $plan->rewindTo(ImportPlanStep::Preview);
        self::assertSame(ImportPlanStep::Workspace, $plan->getStep());
        self::assertFalse($plan->option(TaskSyncImportPlan::OPTION_COMMENTS, true));
        self::assertTrue($plan->option(TaskSyncImportPlan::OPTION_ATTACHMENTS, true));
        self::assertSame([['conn-2', 'Other'], ['9001', 'Acme'], ['f1']], [[$plan->getConnectionRef(), $plan->getConnectionLabel()], [$plan->getWorkspaceId(), $plan->getWorkspaceName()], $plan->getExcludedFields()]);

        $plan->markStarted('u2', $now, 'lock');
        self::assertSame([ImportPlanStatus::Queued, 'u2', $now, 'lock'], [$plan->getStatus(), $plan->getStartedById(), $plan->getStartedAt(), $plan->getLockKey()]);
        self::assertSame([null, null, false, null], [$plan->getResumeAt(), $plan->getErrorCode(), $plan->isCancelRequested(), $plan->getRunToken()]);
        $plan->setReport(['created' => 3, 'board' => 'b', 'codes' => ['a', 1]]);
        self::assertSame([3, 0, ['a'], []], [$plan->reportInt('created'), $plan->reportInt('board'), $plan->reportList('codes'), $plan->reportList('created')]);
        self::assertNull($plan->getFinishedAt());
        $plan->bumpVersion();
        self::assertSame(2, $plan->getVersion());
        self::assertNull($plan->getDirectory());
        self::assertNull($plan->getHeartbeatAt());
        $plan->setDirectory(null);

        (new ReflectionProperty(TaskSyncImportPlan::class, 'status'))->setValue($plan, 'nonsense');
        (new ReflectionProperty(TaskSyncImportPlan::class, 'layout'))->setValue($plan, 'nonsense');
        (new ReflectionProperty(TaskSyncImportPlan::class, 'reimportMode'))->setValue($plan, 'nonsense');
        (new ReflectionProperty(TaskSyncImportPlan::class, 'step'))->setValue($plan, 'nonsense');
        self::assertSame([ImportPlanStatus::Failed, ImportPlanLayout::PerList, ReimportMode::UpdateChanged, ImportPlanStep::Connect], [$plan->getStatus(), $plan->getLayout(), $plan->getReimportMode(), $plan->getStep()]);
    }

    public function testTheItemRow(): void
    {
        $plan = new TaskSyncImportPlan(null, 'clickup', 'conn-1', 'Team', null, new DateTimeImmutable());
        $item = new TaskSyncImportPlanItem($plan, 2, 'folder', 'f:1', 'Roadmap', ['Space'], 'Roadmap');
        self::assertSame([$plan, 2, 'folder', 'f:1', 'Roadmap', ['Space'], 'Roadmap', null, true], [$item->getPlan(), $item->getPosition(), $item->getSourceType(), $item->getSourceId(), $item->getSourceLabel(), $item->getSourcePath(), $item->getBoardName(), $item->getBoardId(), $item->isCreateBoard()]);
        self::assertNull($item->getRemoteStatuses());
        $item->setRemoteStatuses([new RemoteStatus('Doing', RemoteStatusType::Custom, '#fff'), new RemoteStatus('Other')]);
        $statuses = $item->getRemoteStatuses();
        self::assertNotNull($statuses);
        self::assertSame(['Doing', RemoteStatusType::Custom, '#fff', 0], [$statuses[0]->name, $statuses[0]->type, $statuses[0]->color, $statuses[0]->position]);
        self::assertNull($statuses[1]->type);

        $item->targetBoard('b1', 'Board');
        self::assertSame(['b1', 'Board', false], [$item->getBoardId(), $item->getBoardName(), $item->isCreateBoard()]);
        $item->targetNewBoard('New');
        self::assertSame([null, 'New', true], [$item->getBoardId(), $item->getBoardName(), $item->isCreateBoard()]);
        $item->setStatusMap([['status' => 'Doing', 'column' => '__create']]);
        self::assertSame([['status' => 'Doing', 'column' => '__create']], $item->getStatusMap());

        self::assertSame([ImportPlanItemStatus::Pending, ImportPlanPhase::Link], [$item->getStatus(), $item->getPhase()]);
        $item->advance(ImportPlanItemStatus::Failed, ImportPlanPhase::Done, 'x');
        $item->setRunId('r');
        $item->setReport(['created' => 1]);
        self::assertSame(['x', 'r', ['created' => 1]], [$item->getErrorCode(), $item->getRunId(), $item->getReport()]);
        $item->reset();
        self::assertSame([ImportPlanItemStatus::Pending, ImportPlanPhase::Link, null, null], [$item->getStatus(), $item->getPhase(), $item->getErrorCode(), $item->getRunId()]);
        $item->setConnectionId('c');
        $item->reset();
        self::assertSame(['c', ImportPlanPhase::Sync], [$item->getConnectionId(), $item->getPhase()]);
        self::assertNotSame('', $item->getId());

        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'status'))->setValue($item, 'nonsense');
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'phase'))->setValue($item, 'nonsense');
        self::assertSame([ImportPlanItemStatus::Failed, ImportPlanPhase::Link], [$item->getStatus(), $item->getPhase()]);
    }

    public function testPreviewAccessEventExceptionAndLockKey(): void
    {
        $row     = ['itemId' => 'i', 'label' => 'L', 'board' => 'B', 'newBoard' => true, 'tasks' => 150, 'subtasks' => 1, 'closed' => 2, 'archived' => 0, 'attachments' => 3, 'attachmentBytes' => 10, 'columns' => 1, 'estimated' => false, 'error' => null];
        $preview = new PlanPreview([$row, ['newBoard' => false, 'tasks' => 50] + $row], 181, 90, [PlanPreview::WARNING_ESTIMATED]);
        self::assertSame([3, 200, 1, true, false], [$preview->minutes(), $preview->total('tasks'), $preview->boardsToCreate(), $preview->hasWarning('estimated'), $preview->hasWarning('x')]);
        self::assertSame(1, (new PlanPreview([], 0, 0))->minutes());

        $event = new TenantAccessCheckEvent(new stdClass(), 'acme', true);
        self::assertSame([TenantAccessCheckEvent::ACTION_IMPORT_PLAN, 'acme', true, null], [$event->getAction(), $event->getTenantKey(), $event->isAllowed(), $event->getSubjectId()]);
        self::assertInstanceOf(stdClass::class, $event->getSubject());
        $event->deny();
        self::assertSame([false, true, false, true], [$event->isAllowed(), $event->isDenied(), $event->isGranted(), $event->isDefaultGranted()]);
        $event->grant();
        self::assertSame([true, true, false], [$event->isAllowed(), $event->isGranted(), $event->isDenied()]);

        $exception = new ImportPlanException(ImportPlanException::QUOTA, 'no more boards');
        self::assertSame(['quota', 'no more boards', 'Import plan refused: quota'], [$exception->reason, $exception->detail, $exception->getMessage()]);
        self::assertSame(64, strlen(ImportPlanStore::lockKey(null, 'clickup', '1')));
        self::assertNotSame(ImportPlanStore::lockKey('a', 'clickup', '1'), ImportPlanStore::lockKey('b', 'clickup', '1'));
    }

    public function testExcludedFieldsOfAConnection(): void
    {
        $connection = (new ReflectionClass(TaskBoardConnection::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TaskBoardConnection::class, 'options'))->setValue($connection, []);
        self::assertSame([], $connection->excludedFields());
        (new ReflectionProperty(TaskBoardConnection::class, 'options'))->setValue($connection, [TaskBoardConnection::OPTION_EXCLUDED_FIELDS => "a\nb"]);
        self::assertSame(['a', 'b'], $connection->excludedFields());
    }
}
