<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlanItem;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanService;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStep;
use Nowo\TaskBoardBundle\Sync\Provider\CredentialScheme;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\Status\StatusColumns;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Nowo\TaskBoardBundle\Tests\Support\TwinPorts\TwinPortAdapters;
use ReflectionProperty;

use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function substr;

/**
 * Since 3.2.0 (CU-2, design §8.1 – §8.6): the wizard steps of the service — layouts, existing boards, status tables,
 * fields, stale versions and every refusal.
 */
final class ImportPlanServiceTest extends ImportPlanTestCase
{
    public function testLayoutsExpandTheChosenContainers(): void
    {
        self::assertSame(['L1', 'L2'], $this->sources($this->workspacePlan(), ['s:1'], ImportPlanLayout::PerList));
        self::assertSame(['L1', 'f:1'], $this->sources($this->workspacePlan(), ['s:1'], ImportPlanLayout::PerFolder));
        self::assertSame(['f:1'], $this->sources($this->workspacePlan(), ['f:1'], ImportPlanLayout::PerFolder));
        self::assertSame(['L2'], $this->sources($this->workspacePlan(), ['f:1', 'L2'], ImportPlanLayout::PerList));
        self::assertSame(['s:1'], $this->sources($this->workspacePlan(), ['s:1'], ImportPlanLayout::Single));

        $plan = $this->workspacePlan();
        self::assertSame(['s:1'], array_map(static fn (RemoteContainer $node): string => $node->id, $this->plans()->level($plan, null)));
        self::assertSame(['L1', 'f:1'], array_map(static fn (RemoteContainer $node): string => $node->id, $this->plans()->level($plan, 's:1')));
        $this->refused(ImportPlanException::INVALID, fn (): array => $this->plans()->level($plan, 'bad id'));
        $this->refused(ImportPlanException::NO_SOURCES, fn () => $this->plans()->chooseSources($plan, $plan->getVersion(), [], ImportPlanLayout::PerList, []));
        $this->refused(ImportPlanException::INVALID, fn () => $this->plans()->chooseSources($plan, $plan->getVersion(), ['bad id'], ImportPlanLayout::PerList, []));
        $this->refused(ImportPlanException::SINGLE_NEEDS_ONE, fn () => $this->plans()->chooseSources($plan, $plan->getVersion(), ['L1', 'L2'], ImportPlanLayout::Single, []));
        $this->refused(ImportPlanException::INVALID, fn () => $this->plans()->chooseSources($plan, $plan->getVersion(), ['L9'], ImportPlanLayout::PerList, []));
        $this->provider->failOn('findSource:s:1', new ProviderException(ProviderErrorCode::Timeout));
        $this->refused(ImportPlanException::PROVIDER, fn () => $this->plans()->chooseSources($plan, $plan->getVersion(), ['s:1'], ImportPlanLayout::PerList, []));
        $this->provider->failOn('listContainers:s:1', new ProviderException(ProviderErrorCode::Timeout));
        $this->refused(ImportPlanException::PROVIDER, fn () => $this->plans()->chooseSources($plan, $plan->getVersion(), ['s:1'], ImportPlanLayout::PerList, []));
        $this->refused(ImportPlanException::STALE, fn () => $this->plans()->chooseSources($plan, 99, ['L1'], ImportPlanLayout::PerList, []));
    }

    public function testConnectionsWorkspacesAndCredentials(): void
    {
        $plans = $this->plans();
        $plan  = $plans->draft(null, $this->admin, 'clickup');
        self::assertTrue($plans->isAvailable());
        self::assertSame('/settings/connections/clickup', $plans->credentialFormUrl($plan));
        TestCredentialResolver::$connectUrl = '/connect';
        self::assertSame('/connect', $plans->connectUrl($plan, '/back'));
        $this->refused(ImportPlanException::CREDENTIAL, static fn (): ProviderCredential => $plans->credential($plan));
        $this->refused(ImportPlanException::CREDENTIAL, fn (): ConnectionChoice => $plans->choice($plan, $this->admin));

        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        $this->refused(ImportPlanException::INVALID, fn () => $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:9', $this->admin));
        $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:1', $this->admin);
        $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:1', $this->admin);
        self::assertSame(['1', 'Workspace 1'], [$plan->getWorkspaceId(), $plan->getWorkspaceName()]);
        self::assertNotNull($plan->getDirectory());
        self::assertSame('tenant:', substr($plan->getDirectory()?->getScopeKey(), 0, 7));
        self::assertSame(ImportPlanStep::Tree, $plan->getStep());

        // An OAuth connection authorised for other workspaces offers none of these.
        TestCredentialResolver::$choices = [new ConnectionChoice('conn-1', 'Team', '…oken', CredentialScheme::OAuth, ['42'])];
        self::assertSame([], $plans->workspaces($plan, $this->admin));
        // Another connection: the workspace and what follows are chosen again.
        TestCredentialResolver::$choices = [new ConnectionChoice('conn-2', 'Other', '…abcd')];
        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        self::assertSame(ImportPlanStep::Workspace, $plan->getStep());

        (new ReflectionProperty(TaskSyncImportPlan::class, 'provider'))->setValue($plan, 'gone');
        $this->refused(ImportPlanException::PROVIDER, static fn (): TaskProviderInterface => $plans->provider($plan));
        if (!TwinPortAdapters::enabled()) {
            // (The twin host source always implements the tenant interface.)
            TestCredentialResolver::$tenants = false;
            self::assertFalse($plans->isAvailable());
            self::assertSame([], $plans->connections($plan, $this->admin));
            self::assertNull($plans->credentialFormUrl($plan));
        }
        self::assertSame('1', ImportPlanService::workspaceIdOf('w:1'));
        self::assertSame('x', ImportPlanService::workspaceIdOf('x'));
    }

    public function testBoardsStatusesFieldsAndPeople(): void
    {
        $plans    = $this->plans();
        $existing = $this->board('Existing', ['To do', 'Doing', 'Done'], $this->admin);
        $other    = $this->board('Other', ['Open'], $this->admin);
        $plan     = $this->workspacePlan();
        $plans->chooseSources($plan, $plan->getVersion(), ['L1', 'L2'], ImportPlanLayout::PerList, []);
        self::assertSame(['Existing', 'Other'], array_map(static fn (TaskBoard $board): string => $board->getName(), $plans->targetBoards($plan, $this->admin)));
        self::assertSame([], $plans->targetBoards($plan, $this->owner));
        [$first, $second] = $this->store()->items($plan);

        $this->refused(ImportPlanException::BOARD_UNAVAILABLE, fn () => $plans->chooseBoards($plan, $plan->getVersion(), [$first->getId() => ['target' => 'nope', 'name' => '']], $this->admin));
        // A refused step leaves nothing behind (one transaction); the caller reads the plan again.
        $plan = $this->fresh($plan);
        $this->refused(ImportPlanException::BOARD_UNAVAILABLE, fn () => $plans->chooseBoards($plan, $plan->getVersion(), [
            $first->getId()  => ['target' => $existing->getId(), 'name' => ''],
            $second->getId() => ['target' => $existing->getId(), 'name' => ''],
        ], $this->admin));
        $plan = $this->fresh($plan);
        $plans->chooseBoards($plan, $plan->getVersion(), [$first->getId() => ['target' => $existing->getId(), 'name' => ''], $second->getId() => ['target' => 'new', 'name' => "  \x01 "]], $this->admin);
        $items = $this->store()->items($plan);
        self::assertSame([$existing->getId(), false], [$items[0]->getBoardId(), $items[0]->isCreateBoard()]);
        self::assertSame('Bugs', $items[1]->getBoardName(), 'an empty name keeps the list name');

        // Statuses: the existing board's columns, same names preselected; a forged column is refused.
        $groups = $plans->statusGroups($plan);
        self::assertCount(2, $groups);
        $columns = $groups[0]['columns'];
        self::assertSame(['To do', 'Doing', 'Done'], array_values($columns));
        self::assertSame(array_key_first($columns), ImportPlanService::defaultColumn('TO DO', $columns));
        self::assertSame(StatusColumns::CREATE, ImportPlanService::defaultColumn('Review', $columns));
        $this->refused(ImportPlanException::INVALID, static fn () => $plans->mapStatuses($plan, $plan->getVersion(), [$groups[0]['key'] => ['forged']]));
        $plan = $this->fresh($plan);
        $plans->mapStatuses($plan, $plan->getVersion(), [$groups[0]['key'] => [StatusColumns::SKIP]]);
        $map = $this->store()->items($plan)[0]->getStatusMap();
        self::assertSame([StatusColumns::SKIP, 'open'], [$map[0]['column'], $map[0]['type'] ?? null]);
        self::assertSame(array_keys($columns)[1], $map[1]['column']);

        // Another board for the first item: its map is chosen again; back to a new board: idem.
        $plans->chooseBoards($plan, $plan->getVersion(), [$first->getId() => ['target' => $other->getId(), 'name' => '']], $this->admin);
        self::assertSame([], $this->store()->items($plan)[0]->getStatusMap());
        $plans->chooseBoards($plan, $plan->getVersion(), [], $this->admin);
        self::assertTrue($this->store()->items($plan)[0]->isCreateBoard());

        // Fields: counted per source; a forged id is refused.
        self::assertSame([['id' => 'fld1', 'name' => 'Size', 'type' => 'text', 'sources' => 1], ['id' => 'fld2', 'name' => 'Team', 'type' => 'text', 'sources' => 1]], $plans->fields($plan));
        $this->refused(ImportPlanException::INVALID, static fn () => $plans->chooseFields($plan, $plan->getVersion(), ['bad id']));
        $plans->chooseFields($plan, $plan->getVersion(), ['fld2', 'fld2']);
        self::assertSame(['fld2'], $this->fresh($plan)->getExcludedFields());
        $plan = $this->fresh($plan);
        $plans->choosePeople($plan, $plan->getVersion(), true);
        self::assertTrue($plan->option(TaskSyncImportPlan::OPTION_PEOPLE, false));

        // A source gone: no fields from it; its statuses cannot be read: refused.
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'sourceId'))->setValue($this->store()->items($plan)[0], 'L9');
        self::assertCount(0, $plans->fields($plan));
        $item = $this->store()->items($plan)[1];
        (new ReflectionProperty(TaskSyncImportPlanItem::class, 'remoteStatuses'))->setValue($item, null);
        $this->provider->failOn('listStatuses:L2', new ProviderException(ProviderErrorCode::Timeout));
        $this->refused(ImportPlanException::PROVIDER, static fn (): array => $plans->statusGroups($plan));

        $plans->delete($plan);
        self::assertNull($this->store()->find($plan->getId()));
    }

    private function workspacePlan(): TaskSyncImportPlan
    {
        $plans = $this->plans();
        $plan  = $plans->draft(null, $this->admin, 'clickup');
        $plans->chooseConnection($plan, $plan->getVersion(), $plans->connections($plan, $this->admin)[0]);
        $plans->chooseWorkspace($plan, $plan->getVersion(), 'w:1', $this->admin);

        return $plan;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sources(TaskSyncImportPlan $plan, array $ids, ImportPlanLayout $layout): array
    {
        $this->plans()->chooseSources($plan, $plan->getVersion(), $ids, $layout, []);

        return array_map(static fn (TaskSyncImportPlanItem $item): string => $item->getSourceId(), $this->store()->items($plan));
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
