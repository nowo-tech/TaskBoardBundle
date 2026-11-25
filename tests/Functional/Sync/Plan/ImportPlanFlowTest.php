<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\Plan;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Entity\TaskBoardConnection;
use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncImportPlan;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\TestCredentialResolver;
use Nowo\TaskBoardBundle\Tests\Support\TwinPorts\TwinPortAdapters;
use Symfony\Component\DomCrawler\Crawler;

use function in_array;
use function is_array;
use function json_decode;

/**
 * Since 3.2.0 (CU-2, design §8): the guided import over HTTP, without JavaScript — connect, workspace, tree (a level
 * opened by a POST), boards, statuses, fields, people, preview and start (the inline runner imports at once), then the
 * summary, the progress JSON, the report, a re-import that duplicates nothing, and delete.
 */
final class ImportPlanFlowTest extends ImportPlanTestCase
{
    private const BASE = '/tools/task-board/import-plans';

    public function testOnlyGrantedUsersReachThePlans(): void
    {
        $this->client->request('GET', self::BASE);
        self::assertFalse($this->client->getResponse()->isSuccessful(), 'signed in only');
        $this->login($this->owner);
        $this->client->request('GET', self::BASE);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/tools/task-board');
        self::assertSelectorNotExists('[data-testid="nowo-task-board-import-plans"]');

        $this->login($this->admin);
        $this->client->request('GET', '/tools/task-board');
        self::assertSelectorExists('[data-testid="nowo-task-board-import-plans"]');
        $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-plans-empty"]');

        // A plan of another tenant, an unknown id, a host without tenant connections: 404.
        $other = $this->plans()->draft('other', $this->admin, 'clickup');
        $this->client->request('GET', self::BASE . '/' . $other->getId());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::BASE . '/00000000-0000-4000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
        if (!TwinPortAdapters::enabled()) {
            // (The twin host source always implements the tenant interface.)
            TestCredentialResolver::$tenants = false;
            $this->client->request('GET', self::BASE);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testTheWholeWizardImportsSeveralBoards(): void
    {
        $this->board('Already here', ['To do'], $this->admin);
        $this->login($this->admin);
        $this->client->request('POST', self::BASE . '/new', ['_token' => PredictableCsrfTokenManager::value('task_board_import_plan_new')]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="nowo-task-plan-privacy"]');
        self::assertSelectorExists('[data-testid="nowo-task-plan-token"]');

        // 1. Connect.
        $crawler = $this->next($crawler, ['connection' => 'conn-1']);
        self::assertSelectorTextContains('[data-testid="nowo-task-plan"]', 'Workspace 1');
        // 2. Workspace.
        $crawler = $this->next($crawler, ['workspace' => 'w:1']);
        // 3. Tree: open the space (a POST answered with the page), tick it.
        self::assertSelectorTextContains('[data-testid="nowo-task-plan"]', 'Space 1');
        $form = $crawler->filter('[data-testid="nowo-task-plan-node-open"]')->first()->form();
        foreach ($form['task_board_import_plan[containers]'] as $box) {
            $box->tick();
        }
        $crawler = $this->client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSame('s:1', $crawler->filter('input[type="checkbox"][value="s:1"]')->attr('checked') !== null ? 's:1' : '', 'a ticked box stays ticked');
        self::assertSelectorTextContains('[data-testid="nowo-task-plan"]', 'Backlog');
        $crawler = $this->next($crawler, ['containers' => ['s:1'], 'layout' => 'per_list', 'attachments' => false]);
        // 4. Boards: one per list (the folder brought its list); rename one.
        self::assertCount(2, $crawler->filter('[data-testid="nowo-task-plan-board-row"]'));
        $plan  = $this->latestPlan();
        $items = $this->itemsOf($plan);
        self::assertSame(['L1', 'L2'], [$items[0]->getSourceId(), $items[1]->getSourceId()]);
        $crawler = $this->next($crawler, ['n_' . $items[0]->getId() => 'Backlog board']);
        // 5. Statuses (Backlog's three statuses; Bugs has none).
        self::assertCount(2, $crawler->filter('[data-testid="nowo-task-plan-status-table"]'));
        $crawler = $this->next($crawler, []);
        // 6. Fields: leave "Team" out.
        $crawler = $this->next($crawler, ['imported' => ['fld1']]);
        // 7. People.
        self::assertSelectorExists('[data-testid="nowo-task-plan-people-counts"]');
        $crawler = $this->next($crawler, []);
        // 8. Preview, then start (the inline runner imports in the request).
        self::assertSelectorExists('[data-testid="nowo-task-plan-eta"]');
        self::assertCount(2, $crawler->filter('[data-testid="nowo-task-plan-preview-row"]'));
        $this->client->submit($crawler->filter('#nowo-task-plan-start-form')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="nowo-task-plan-summary"]');

        $plan = $this->fresh($plan);
        self::assertSame(ImportPlanStatus::Succeeded, $plan->getStatus());
        self::assertSame(3, $plan->reportInt('created'));
        $backlog = $this->boardNamed('Backlog board');
        self::assertSame('Space 1 › Backlog', $backlog->getDescription());
        self::assertCount(2, $this->tasksOf($backlog));
        $connection = $this->connectionOf($backlog);
        self::assertInstanceOf(TaskBoardConnection::class, $connection);
        self::assertSame(['fld2'], $connection->excludedFields());
        self::assertFalse($connection->option('importAttachments', true));
        self::assertSame($plan->getDirectory()?->getId(), $connection->getDirectory()?->getId());
        self::assertSame($plan->getDirectory()?->getId(), $this->connectionOf($this->boardNamed('Bugs'))?->getDirectory()?->getId(), 'one directory for the tenant and workspace');

        // Progress JSON and report: counts and ids only.
        $this->client->request('GET', self::BASE . '/' . $plan->getId() . '/progress');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($json['terminal']);
        self::assertCount(2, $json['items']);
        $this->client->request('GET', self::BASE . '/' . $plan->getId() . '/report');
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringNotContainsString('pk_test_token', (string) $this->client->getResponse()->getContent());

        // Re-import now, only new tasks: nothing duplicated, the new remote task arrives.
        $this->put($this->remote('p3', 'New one', 9));
        $form = $crawler->filter('[data-testid="nowo-task-plan-reimport"]')->form();
        $form['task_board_import_plan[mode]']->select('add_new');
        $this->client->submit($form);
        self::assertSame(ImportPlanStatus::Succeeded, $this->fresh($plan)->getStatus());
        self::assertCount(3, $this->tasksOf($this->boardNamed('Backlog board')));
        self::assertCount(4, $this->em->getRepository(Task::class)->findAll());

        // The list page, then delete: the boards stay.
        $this->client->request('GET', self::BASE);
        self::assertSelectorExists('[data-testid="nowo-task-plan-row"]');
        $this->client->request('POST', self::BASE . '/' . $plan->getId() . '/delete', ['_token' => PredictableCsrfTokenManager::value('task_board_import_plan_delete')]);
        self::assertResponseRedirects(self::BASE);
        self::assertNull($this->store()->find($plan->getId()));
        $this->boardNamed('Bugs');
    }

    public function testStepsRefuseStaleAndForgedPostsAndKeepTheOrder(): void
    {
        $this->login($this->admin);
        $plan = $this->plans()->draft(null, $this->admin, 'clickup');
        $url  = self::BASE . '/' . $plan->getId();

        // A step not reached yet: 404; a GET for it shows the furthest step.
        $this->client->request('POST', $url . '/step/tree', []);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $url . '?step=preview');
        self::assertSelectorExists('[data-step="connect"]');

        // No CSRF token: 403. A forged connection: 422 with the page.
        $this->client->request('POST', $url . '/step/connect', ['task_board_import_plan' => ['version' => '1', 'connection' => 'conn-1']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url . '/step/connect', ['task_board_import_plan' => ['version' => '1', 'connection' => 'forged', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_connect')]]);
        self::assertResponseStatusCodeSame(422);

        // A stale version: refused with a message, nothing changed.
        $this->client->request('POST', $url . '/step/connect', ['task_board_import_plan' => ['version' => '7', 'connection' => 'conn-1', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_connect')]]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'changed elsewhere');
        self::assertSame('', $this->fresh($plan)->getConnectionRef());

        // A tree level with a bad id is not opened; a CSRF-less open is refused.
        $this->plans()->chooseConnection($plan = $this->fresh($plan), $plan->getVersion(), $this->plans()->connections($plan, $this->admin)[0]);
        $this->plans()->chooseWorkspace($plan = $this->fresh($plan), $plan->getVersion(), 'w:1', $this->admin);
        $this->client->request('POST', $url . '/step/tree', ['open' => 's:1', 'task_board_import_plan' => ['version' => '3']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url . '/step/tree', ['task_board_import_plan' => ['version' => '3', 'opened' => 's:1,bad id', 'layout' => 'single', 'containers' => ['s:1', 'L1'], '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_tree')]]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'exactly one');

        // Back links of a done step, the connect page without connection.
        TestCredentialResolver::$choices = [];
        $this->client->request('GET', $url . '?step=connect');
        self::assertSelectorExists('[data-testid="nowo-task-plan-no-connection"]');
        $this->client->request('POST', $url . '/step/workspace', ['task_board_import_plan' => ['version' => '3', 'workspace' => 'w:1', '_token' => PredictableCsrfTokenManager::value('task_board_import_plan_workspace')]]);
        self::assertResponseRedirects();
    }

    /**
     * Submits the step's form with `$values` (field names without the form name) and follows the redirect.
     *
     * @param array<string, mixed> $values
     */
    private function next(Crawler $crawler, array $values): Crawler
    {
        $form = $crawler->filter('[data-testid="nowo-task-plan-next"]')->form();
        foreach ($values as $name => $value) {
            $field = $form['task_board_import_plan[' . $name . ']'];
            if (is_array($value)) {
                foreach ($field as $checkbox) {
                    $checkbox->tick();
                    if (!in_array($checkbox->availableOptionValues()[0], $value, true)) {
                        $checkbox->untick();
                    }
                }
            } elseif ($value === false) {
                $field->untick();
            } else {
                $field->setValue($value);
            }
        }
        $this->client->submit($form);
        self::assertResponseRedirects();

        return $this->client->followRedirect();
    }

    private function latestPlan(): TaskSyncImportPlan
    {
        $plan = $this->store()->forTenant(null, 1)[0] ?? null;
        self::assertInstanceOf(TaskSyncImportPlan::class, $plan);

        return $plan;
    }
}
