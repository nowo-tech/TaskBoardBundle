<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Event\TenantAccessCheckEvent;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderErrorCode;
use Nowo\TaskBoardBundle\Sync\Exception\ProviderException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanException;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Provider\ContainerType;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteContainer;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\TaskBoardSyncService;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_unique;
use function array_values;

/**
 * Since 3.2.0 (audit ZBY): the slices run as the plan's starter whoever polls or resumes it (M1), a demoted or deleted
 * starter stops the plan (M1), sources stay in the plan's workspace (L1), the board quota is asked per board (L3),
 * provider errors of the steps are messages (L4), a board linked again elsewhere is not re-imported (I1) and the plans
 * of a user are exported (I6). With the inline runner (the default).
 */
final class ImportPlanAuditZbyTest extends ImportPlanTestCase
{
    private const BASE = '/tools/task-board/import-plans/';

    private AppUser $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = $this->user('admin2@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    public function testTheSlicesRunAsTheStarterWhoeverPollsOrResumes(): void
    {
        // A starts; the People option pauses after the first board is linked.
        $plan = $this->preparedPlan(['L1'], options: ['peopleFirst' => true]);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame('people_mapping_required', $this->fresh($plan)->getErrorCode());

        // B resumes: the import runs, as A.
        $this->login($this->other);
        $this->client->request('POST', self::BASE . $plan->getId() . '/resume', ['_token' => PredictableCsrfTokenManager::value('task_board_import_plan_resume')]);
        self::assertSame(ImportPlanStatus::Succeeded, $this->fresh($plan)->getStatus());
        self::assertSame([(string) $this->admin->getId()], $this->creatorsOf('Backlog'));

        // A plan due again after an outage: B's poll runs the slice, still as A.
        $second = $this->preparedPlan(['L2']);
        $this->provider->failOn('fetchTasks:L2', new ProviderException(ProviderErrorCode::Timeout));
        $this->plans()->start($second, $second->getVersion(), $this->admin);
        self::assertSame(ImportPlanStatus::Paused, $this->fresh($second)->getStatus());
        $this->due($second->getId());
        $this->client->request('GET', self::BASE . $second->getId() . '/progress');
        self::assertSame(ImportPlanStatus::Succeeded, $this->fresh($second)->getStatus());
        self::assertSame([(string) $this->admin->getId()], $this->creatorsOf('Bugs'));
    }

    public function testADemotedOrDeletedStarterStopsThePlan(): void
    {
        $plan = $this->preparedPlan(['L1']);
        $this->provider->failOn('fetchTasks:L1', new ProviderException(ProviderErrorCode::Timeout));
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);

        // A loses import_plan in the tenant (still enabled, still a user): B's poll does not run the plan as B.
        $adminId = (string) $this->admin->getId();
        $deny    = static function (TenantAccessCheckEvent $event) use ($adminId): void {
            if ($event->getSubjectId() === $adminId) {
                $event->deny();
            }
        };
        $this->dispatcher()->addListener(TaskBoardEvents::TENANT_ACCESS_CHECK, $deny);
        $this->due($plan->getId());
        $this->login($this->other);
        $this->client->request('GET', self::BASE . $plan->getId() . '/progress');
        self::assertSame([ImportPlanStatus::Failed, 'actor'], [$this->fresh($plan)->getStatus(), $this->fresh($plan)->getErrorCode()]);
        self::assertSame([], $this->creatorsOf('Backlog'));
        $this->dispatcher()->removeListener(TaskBoardEvents::TENANT_ACCESS_CHECK, $deny);

        // Started again by B, then B is erased: nobody runs it.
        $plan = $this->fresh($plan);
        $this->provider->failOn('fetchTasks:L1', new ProviderException(ProviderErrorCode::Timeout));
        $this->plans()->start($plan, $plan->getVersion(), $this->other);
        $this->store()->forgetUser((string) $this->other->getId());
        $this->due($plan->getId());
        self::assertSame(ImportPlanStatus::Failed, $this->runner()->runSlice($plan->getId(), 60));
        self::assertSame('actor', $this->fresh($plan)->getErrorCode());
    }

    public function testSourcesStayInThePlansWorkspace(): void
    {
        $plans = $this->plans();
        $plan  = $plans->draft(null, $this->admin, 'clickup');
        $plans->chooseConnection($plan, 1, $plans->connections($plan, $this->admin)[0]);
        $plans->chooseWorkspace($plan, 2, 'w:1', $this->admin);

        // The provider says the list is in another workspace: refused; and when it cannot tell: a message.
        $this->provider->withWorkspace('2', [new RemotePerson('7', 'Ada Example', 'ada@example.test')]);
        $this->refused(ImportPlanException::INVALID, static fn () => $plans->chooseSources($plan, 3, ['L1'], ImportPlanLayout::PerList, []));
        $this->provider->withWorkspace('1');
        $this->provider->failOn('workspaceOf:L1', new ProviderException(ProviderErrorCode::Timeout));
        $this->refused(ImportPlanException::PROVIDER, static fn () => $plans->chooseSources($plan, 3, ['L1'], ImportPlanLayout::PerList, []));

        // A level the page never listed is not opened (the folder is under a space that was not opened).
        $this->login($this->admin);
        $this->client->request('POST', self::BASE . $plan->getId() . '/step/tree', ['open' => 'f:1', 'task_board_import_plan' => ['version' => '3', 'opened' => 'f:9', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_tree')]]);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Bugs', (string) $this->client->getResponse()->getContent());
    }

    public function testTheBoardQuotaIsAskedForEachBoard(): void
    {
        // The host refuses a single board (as when another plan took the last ones meanwhile) but not the plan's total.
        $deny = static function (TaskBoardQuotaCheckEvent $event): void {
            if ($event->action === TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE && $event->count === 1) {
                $event->deny('Your plan is full.');
            }
        };
        $this->dispatcher()->addListener(TaskBoardEvents::QUOTA_CHECK, $deny);
        $plan = $this->preparedPlan(['L1', 'L2']);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        $items = $this->itemsOf($plan);
        self::assertSame([[ImportPlanItemStatus::Failed, 'quota'], [ImportPlanItemStatus::Failed, 'quota']], [[$items[0]->getStatus(), $items[0]->getErrorCode()], [$items[1]->getStatus(), $items[1]->getErrorCode()]]);
        self::assertSame('items_failed', $this->fresh($plan)->getErrorCode());
        $this->dispatcher()->removeListener(TaskBoardEvents::QUOTA_CHECK, $deny);
    }

    public function testProviderErrorsOfTheStepsAreMessages(): void
    {
        $this->login($this->admin);
        $plans = $this->plans();
        $plan  = $plans->draft(null, $this->admin, 'clickup');
        $plans->chooseConnection($plan, 1, $plans->connections($plan, $this->admin)[0]);
        $this->provider->failOn('listContainers:', new ProviderException(ProviderErrorCode::ServerError));
        $this->client->request('GET', self::BASE . $plan->getId() . '?step=workspace');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-plan-error"]');

        $ready = $this->preparedPlan(['L1']);
        $this->provider->failOn('listCustomFields:L1', new ProviderException(ProviderErrorCode::ServerError));
        $this->client->request('GET', self::BASE . $ready->getId() . '?step=fields');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-plan-error"]');
    }

    public function testABoardLinkedElsewhereIsNotReimportedAndPlansAreExported(): void
    {
        $plan = $this->preparedPlan(['L1']);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame(ImportPlanStatus::Succeeded, $this->fresh($plan)->getStatus());

        // Someone unlinks the board and links it again (a new connection): a re-import leaves it alone.
        $board = $this->em->find(TaskBoard::class, (string) $this->itemsOf($plan)[0]->getBoardId());
        self::assertInstanceOf(TaskBoard::class, $board);
        $sync = self::getContainer()->get(TaskBoardSyncService::class);
        self::assertInstanceOf(TaskBoardSyncService::class, $sync);
        $sync->unlink($board, $this->admin);
        $sync->link($board, $this->provider, new ProviderCredential(TestCredentialResolver::$secret, 'conn-1'), new RemoteContainer('L1', 'Backlog', ContainerType::List, 's:1', true, false), $this->admin);
        $plan = $this->fresh($plan);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        self::assertSame('board_linked_elsewhere', $this->itemsOf($plan)[0]->getErrorCode());

        // Access request: the plans the user created or started, ids and dates only.
        $rows = $this->store()->exportFor((string) $this->admin->getId());
        self::assertSame([$plan->getId(), 'created_started', 'failed'], [$rows[0]['planId'], $rows[0]['role'], $rows[0]['status']]);
        self::assertSame([], $this->store()->exportFor('nobody'));
        $this->sql('UPDATE task_board_sync_import_plans SET started_by_id = ? WHERE id = ?', ['someone-else', $plan->getId()]);
        self::assertSame('created', $this->store()->exportFor((string) $this->admin->getId())[0]['role']);
        self::assertSame('started', $this->store()->exportFor('someone-else')[0]['role']);
    }

    /**
     * The creators' ids of the tasks of the board named `$name` (none when the board does not exist).
     *
     * @return list<string>
     */
    private function creatorsOf(string $name): array
    {
        $this->em->clear();
        $board = $this->em->getRepository(TaskBoard::class)->findOneBy(['name' => $name]);
        if (!$board instanceof TaskBoard) {
            return [];
        }
        $ids = [];
        foreach ($this->em->getRepository(Task::class)->findBy(['board' => $board->getId()]) as $task) {
            $creator = $task->getCreator();
            $ids[]   = $creator instanceof AppUser ? (string) $creator->getId() : '';
        }

        return array_values(array_unique($ids));
    }

    private function due(string $planId): void
    {
        $this->sql('UPDATE task_board_sync_import_plans SET resume_at = ? WHERE id = ?', [(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $planId]);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
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
