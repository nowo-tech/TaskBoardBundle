<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingPlanRunner;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;

use function count;
use function json_decode;

/**
 * Since 3.2.0 (CU-2, design §8.8, §8.9): the run page of a plan a worker runs — progress, Cancel, Resume after the
 * People wait, refusals shown as messages, and the OAuth start of the Connect step.
 */
final class ImportPlanPagesTest extends ImportPlanTestCase
{
    private const BASE = '/tools/task-board/import-plans/';

    protected static function syncConfig(): array
    {
        return ['dashboard_route' => 'nowo_task_board_index'] + parent::syncConfig();
    }

    protected static function planConfig(): array
    {
        return ['runner' => 'app.plan_runner', 'preview_max_requests' => 2];
    }

    public function testTheRunPageOfAWaitingPlan(): void
    {
        $plan = $this->preparedPlan(['L1', 'L2'], options: ['peopleFirst' => true]);
        $this->plans()->start($plan, $plan->getVersion(), $this->admin);
        $this->login($this->admin);

        $this->client->request('GET', self::BASE . $plan->getId() . '?step=connect');
        self::assertSelectorExists('[data-testid="nowo-task-plan-progress"]');
        self::assertSelectorExists('[data-testid="nowo-task-plan-cancel"]');

        // A worker runs it: polling does not advance it; the summary JSON says where it is.
        $this->client->request('GET', self::BASE . $plan->getId() . '/progress');
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['queued', false], [$json['status'], $json['terminal']]);

        // The People wait: the inline runner on the poll leaves it waiting; the page links the People page; Resume.
        $this->runner()->runSlice($plan->getId(), 60);
        RecordingPlanRunner::$onPoll = true;
        $this->client->request('GET', self::BASE . $plan->getId() . '/progress');
        self::assertSame(1, count(RecordingPlanRunner::$requests) - 1, 'the poll asked the runner');
        $this->client->request('GET', self::BASE . $plan->getId());
        self::assertSelectorExists('[data-testid="nowo-task-plan-map-people"]');
        self::assertSelectorTextContains('[data-testid="nowo-task-plan-code"]', 'Waiting for people');
        $this->client->request('POST', self::BASE . $plan->getId() . '/resume', ['_token' => PredictableCsrfTokenManager::value('task_board_import_plan_resume')]);
        self::assertResponseRedirects(self::BASE . $plan->getId());
        self::assertNotNull($this->fresh($plan)->getResumeAt());

        // Refusals become messages: start or delete an active plan.
        $this->client->request('POST', self::BASE . $plan->getId() . '/start', ['task_board_import_plan' => ['version' => (string) $this->fresh($plan)->getVersion(), 'mode' => 'update', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_start')], 'retry' => '1']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'cannot be changed now');
        $this->client->request('POST', self::BASE . $plan->getId() . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_import_plan_delete')]);
        self::assertResponseRedirects(self::BASE . $plan->getId());
        $this->client->request('POST', self::BASE . $plan->getId() . '/start', ['task_board_import_plan' => ['version' => '1', 'mode' => 'update']]);
        self::assertResponseStatusCodeSame(403);

        // Cancel.
        $this->client->request('POST', self::BASE . $plan->getId() . '/cancel', ['_token' => PredictableCsrfTokenManager::value('task_board_import_plan_cancel')]);
        self::assertResponseRedirects();
        self::assertSame(ImportPlanStatus::Cancelled, $this->fresh($plan)->getStatus());
        $this->client->request('GET', self::BASE . $plan->getId());
        self::assertSelectorExists('[data-testid="nowo-task-plan-reimport"]');
        self::assertSelectorExists('[data-testid="nowo-task-plan-report"]');
    }

    public function testStepPagesShowProviderErrorsAndTheOAuthStart(): void
    {
        $this->login($this->admin);
        TestCredentialResolver::$connectUrl = '/oauth/start';
        $plan                               = $this->plans()->draft(null, $this->admin, 'clickup');
        $this->client->request('GET', self::BASE . $plan->getId());
        self::assertSelectorExists('[data-testid="nowo-task-plan-oauth"]');
        self::assertSame(self::BASE . $plan->getId() . '?step=connect', TestCredentialResolver::$returnTo);

        // The workspace step without a usable connection: the error in the page; its POST: a message.
        $this->plans()->chooseConnection($plan, 1, $this->plans()->connections($plan, $this->admin)[0]);
        TestCredentialResolver::$available = false;
        $this->client->request('GET', self::BASE . $plan->getId() . '?step=workspace');
        self::assertSelectorExists('[data-testid="nowo-task-plan-error"]');
        $this->client->request('POST', self::BASE . $plan->getId() . '/step/workspace', ['task_board_import_plan' => ['version' => '2', 'workspace' => 'w:1', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_workspace')]]);
        self::assertResponseRedirects(self::BASE . $plan->getId() . '?step=workspace');
        TestCredentialResolver::$available = true;

        // The preview's start refused by the host's quota: a message, the plan stays a draft.
        $ready                                                = $this->preparedPlan(['L1', 'L2']);
        self::getContainer()->get('app.quota_listener')->deny = ['board_create'];
        $crawler                                              = $this->client->request('GET', self::BASE . $ready->getId());
        self::assertSelectorExists('[data-testid="nowo-task-plan-refusal"]');
        // Audit ZBY-L4: the preview reads at most `preview_max_requests`; the rest is shown unread.
        self::assertSelectorExists('[data-testid="nowo-task-plan-warning-preview_truncated"]');
        self::assertSelectorTextContains('[data-testid="nowo-task-plan-preview-error"]', 'Not read');
        $this->client->submit($crawler->filter('#nowo-task-plan-start-form')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Your plan is full.');
        self::assertSame(ImportPlanStatus::Draft, $this->fresh($ready)->getStatus());
        self::assertInstanceOf(TaskSyncImportPlan::class, $this->fresh($ready));
    }
}
