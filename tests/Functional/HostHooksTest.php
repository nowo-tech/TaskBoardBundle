<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Tests\Functional\App\ManualHelpLinkUrls;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingBoardProvisioner;
use Nowo\TaskBoardBundle\Tests\Functional\App\RecordingQuotaListener;
use Nowo\TaskBoardBundle\Tests\Functional\App\TenantCreateChecker;
use Nowo\UiKitBundle\Form\EmojiPickerType;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Since 2.0.0 (Beacon TB-2, TB-7, TB-8, TB-9): the host hooks that replace Beacon's request listeners.
 */
final class HostHooksTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private const TENANTS = ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]];

    private const JIRA = "Issue key,Summary,Status\nPRJ-1,Spec,To do\nPRJ-2,Payroll run,To do\n";

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): void
    {
        self::$taskBoardConfig = $config;
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);
    }

    private function quota(): RecordingQuotaListener
    {
        $listener = self::getContainer()->get('app.quota_listener');
        self::assertInstanceOf(RecordingQuotaListener::class, $listener);

        return $listener;
    }

    private function provisioner(): RecordingBoardProvisioner
    {
        $provisioner = self::getContainer()->get('app.first_board');
        self::assertInstanceOf(RecordingBoardProvisioner::class, $provisioner);

        return $provisioner;
    }

    public function testTheIndexOpensTheOnlyBoardAndBoards1KeepsTheList(): void
    {
        $this->boot(['tenancy' => self::TENANTS + ['open_single_board' => true]]);
        $board = $this->board('Only', tenantKey: 'acme');

        $this->client->request('GET', '/t/acme');
        self::assertResponseRedirects('/t/acme/board/' . $board->getId());
        $this->client->request('GET', '/t/acme?boards=1');
        self::assertResponseIsSuccessful();
    }

    public function testTheIndexListsTwoBoards(): void
    {
        $this->boot(['tenancy' => self::TENANTS + ['open_single_board' => true]]);
        $this->board('One', tenantKey: 'acme');
        $this->board('Two', tenantKey: 'acme');

        $this->client->request('GET', '/t/acme');
        self::assertResponseIsSuccessful();
    }

    public function testByDefaultTheIndexListsOneBoard(): void
    {
        $this->boot(['tenancy' => self::TENANTS]);
        $this->board('Only', tenantKey: 'acme');

        $this->client->request('GET', '/t/acme');
        self::assertResponseIsSuccessful();
    }

    public function testTheProvisionerCreatesTheFirstBoardAndOpensIt(): void
    {
        $this->boot(['tenancy' => self::TENANTS + ['first_board_provisioner' => 'app.first_board']]);

        $this->client->request('GET', '/t/acme?boards=1');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->provisioner()->calls);

        $this->client->request('GET', '/t/acme');
        $board = $this->em->getRepository(TaskBoard::class)->findOneBy(['tenantKey' => 'acme']);
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertResponseRedirects('/t/acme/board/' . $board->getId());

        // A provisioner that gives nothing leaves the empty index.
        $this->client->request('GET', '/t/empty');
        self::assertResponseIsSuccessful();
    }

    public function testTheProvisionerIsNotAskedForWhoMayNotCreateOrOverQuota(): void
    {
        $this->boot([
            'security' => ['access_checker' => 'app.tenant_create_checker'],
            'tenancy'  => self::TENANTS + ['first_board_provisioner' => 'app.first_board'],
        ]);
        $this->client->request('GET', '/t/other');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->provisioner()->calls);

        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE];
        $this->client->disableReboot();
        $this->client->request('GET', '/t/acme');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->provisioner()->calls);
    }

    public function testATenantTheCheckerRefusesToListAnswers404(): void
    {
        $this->boot(['security' => ['access_checker' => 'app.tenant_create_checker'], 'tenancy' => self::TENANTS]);

        $this->client->request('GET', '/t/acme');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/t/' . TenantCreateChecker::UNLISTED_TENANT);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/t/' . TenantCreateChecker::UNLISTED_TENANT . '/create', ['task_board_form' => ['name' => 'X', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAQuotaRefusalCreatesNoBoardAndSaysWhy(): void
    {
        $this->boot(['board' => ['create_dialog_header' => 'X-Host-Dialog']]);
        $this->client->disableReboot();
        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE];
        $post                = ['task_board_form' => ['name' => 'Over', '_token' => PredictableCsrfTokenManager::value('task_board_form')]];

        $this->client->request('POST', self::BASE . '/create', $post);
        self::assertResponseRedirects(self::BASE);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your plan is full.', $crawler->text());

        $crawler = $this->client->request('POST', self::BASE . '/create', $post, [], ['HTTP_X_HOST_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Your plan is full.', $crawler->text());

        self::assertCount(0, $this->em->getRepository(TaskBoard::class)->findAll());
        $asked = $this->quota()->asked[0];
        self::assertSame(1, $asked->count);
        self::assertNull($asked->board);
        self::assertNull($asked->tenantKey);
        self::assertTrue($asked->isDenied());
    }

    public function testAQuotaRefusalCreatesNoTaskOrSubtask(): void
    {
        $this->boot([]);
        $board  = $this->board(tenantKey: 'acme');
        $parent = $this->task($board, 'Parent');
        $this->client->disableReboot();
        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE];
        $column              = (string) $board->getColumns()->first()->getId();
        $task                = ['title' => 'Over', 'columnId' => $column, '_token' => PredictableCsrfTokenManager::value('task_form')];

        $this->client->request('POST', self::BASE . '/board/' . $board->getId() . '/create?inline=1', ['task_form' => $task]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your plan is full.', $crawler->text());
        $this->client->request('POST', self::BASE . '/task/' . $parent->getId() . '/subtask', ['task_form' => $task]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your plan is full.', $crawler->text());

        self::assertCount(1, $this->em->getRepository(Task::class)->findAll());
        self::assertSame('acme', $this->quota()->asked[0]->tenantKey);
        self::assertSame($board->getId(), $this->quota()->asked[0]->board?->getId());
    }

    public function testAnImportAsksForTheTasksItWouldCreateBeforeWriting(): void
    {
        $this->boot([]);
        $board = $this->board(columns: ['Done']);
        $this->client->disableReboot();
        $this->quota()->deny = [TaskBoardQuotaCheckEvent::ACTION_IMPORT];

        $this->import($board);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Your plan is full.', $crawler->text());
        self::assertCount(0, $this->em->getRepository(Task::class)->findAll());
        self::assertCount(1, $board->getColumns(), 'the missing column was not created');
        self::assertSame(2, $this->quota()->asked[0]->count);

        // Once imported, the same file adds nothing and does not ask.
        $this->quota()->deny  = [];
        $this->quota()->asked = [];
        $this->import($board);
        self::assertCount(2, $this->em->getRepository(Task::class)->findAll());
        self::assertSame(2, $this->quota()->asked[0]->count);
        $this->quota()->asked = [];
        $this->import($board);
        self::assertSame([], $this->quota()->asked);
    }

    public function testNoListenerRefusalKeepsTheWrite(): void
    {
        $this->boot([]);
        $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => 'Fine', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        self::assertResponseRedirects();
        self::assertCount(1, $this->em->getRepository(TaskBoard::class)->findAll());
    }

    public function testTheConfiguredHelpLinkIsTranslatedCheckedAndOpensOffSiteInANewTab(): void
    {
        $this->boot(['import' => ['help_link' => ['url' => 'https://docs.example.test/import', 'label' => 'task_board.import.action', 'translation_domain' => 'NowoTaskBoardBundle']]]);
        $board   = $this->board();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import');
        $link    = $crawler->filter('[data-testid="nowo-task-import-help-link"]');
        self::assertSame('https://docs.example.test/import', $link->attr('href'));
        self::assertSame('Import tasks', $link->text());
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener noreferrer', $link->attr('rel'));
    }

    public function testTheConfiguredHelpLinkFollowsARouteTheSourceAndTheAttribute(): void
    {
        $link = ['route' => 'nowo_task_board_index', 'label' => 'Format', 'anchor_from' => 'source'];
        $this->boot(['import' => ['help_link' => $link + ['access_attribute' => 'ROLE_USER']]]);
        $board   = $this->board();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import');
        $found   = $crawler->filter('[data-testid="nowo-task-import-help-link"]');
        self::assertStringStartsWith(self::BASE . '#', (string) $found->attr('href'));
        self::assertNull($found->attr('target'), 'a route is on this site');
        self::assertCount(1, $crawler->filter('[data-controller="nowo-task-board-select-anchor"]'));

        $this->boot(['import' => ['help_link' => $link + ['access_attribute' => 'ROLE_MANUAL']]]);
        $board   = $this->board('Other');
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import');
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-import-help-link"]'));
    }

    /** Since 2.5.0 (TB-17): the host's url_resolver has the last word on the URL; with it, url and route are optional. */
    public function testTheHelpLinkUrlComesFromTheHostResolver(): void
    {
        ManualHelpLinkUrls::$inApp = false;
        $this->boot(['import' => ['help_link' => ['url_resolver' => 'app.help_link_urls', 'label' => 'Format']]]);
        $board = $this->board();
        $found = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import')->filter('[data-testid="nowo-task-import-help-link"]');
        self::assertSame('https://manual.example.test/import', $found->attr('href'));
        self::assertSame('_blank', $found->attr('target'));

        ManualHelpLinkUrls::$inApp = true;
        $board                     = $this->board('In app');
        self::assertCount(0, $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import')->filter('[data-testid="nowo-task-import-help-link"]'), 'no configured URL and the resolver keeps it: no link');

        $this->boot(['import' => ['help_link' => ['url' => '/manual/import', 'url_resolver' => 'app.help_link_urls', 'label' => 'Format']]]);
        $board = $this->board('Third');
        $found = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import')->filter('[data-testid="nowo-task-import-help-link"]');
        self::assertSame('/manual/import', $found->attr('href'));
    }

    public function testTheTaskTimeSlotRendersOnThePageAndThePanel(): void
    {
        $this->boot(['templates' => ['task_time_before' => 'host_timer.html.twig']]);
        $task = $this->task($this->board(), 'Timed');

        foreach (['', '/panel'] as $suffix) {
            $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . $suffix);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-testid="host-timer"]'), $suffix);
        }
    }

    public function testThePublicHooksExistOnCardsRowsBarsSubtasksAndThePanel(): void
    {
        $this->boot([]);
        $board  = $this->board();
        $parent = $this->task($board, 'Parent');
        $this->task($board, 'Child', parent: $parent);

        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId());
        self::assertSame('To do', $crawler->filter('.nowo-task-card[data-nowo-task-title="Parent"]')->attr('data-nowo-task-column'));
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/list');
        self::assertCount(2, $crawler->filter('tr[data-nowo-task-title]'));
        $crawler = $this->client->request('GET', self::BASE . '/task/' . $parent->getId() . '/panel');
        self::assertCount(1, $crawler->filter('[data-nowo-task-panel]'));
        $child = $crawler->filter('.nowo-task-subtree [role="treeitem"][data-nowo-task-title="Child"]');
        self::assertCount(1, $child);
        self::assertNotNull($child->attr('data-task-panel-url'));
        self::assertSame('Child', $child->attr('data-title'));
    }

    public function testADialogRequestToTheConnectionRoutesIsAnsweredByTheKit(): void
    {
        // TB-6 asks no code of this bundle: UiKit's listener converts the redirect of a dialog request.
        $this->boot(['__ui_kit' => ['remote_dialog' => ['convert_redirects' => true]]]);
        $board = $this->board();
        $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => 'Via dialog', '_token' => PredictableCsrfTokenManager::value('task_board_form')]], [], ['HTTP_X_NOWO_DIALOG' => '1']);
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->client->getResponse()->headers->has('X-Nowo-Redirect'));
        self::assertNotNull($board->getId());
    }

    public function testTheChromeSwitchesLeaveTheHeaderToolbarAndPanelToolbarOut(): void
    {
        $this->boot([
            'templates'  => ['page_header' => false],
            'board'      => ['toolbar' => false],
            'task_panel' => ['toolbar' => false],
        ]);
        $board = $this->board();
        $task  = $this->task($board, 'A task');
        $id    = $board->getId();

        foreach (['/board/' . $id, '/board/' . $id . '/list', '/board/' . $id . '/gantt', '/board/' . $id . '/import'] as $path) {
            $crawler = $this->client->request('GET', self::BASE . $path);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-nowo-ui-region="header"]'), $path);
        }
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $id);
        self::assertCount(0, $crawler->filter('.nowo-task-board__toolbar, .nowo-task-column-sets__save'));
        self::assertCount(1, $crawler->filter('dialog#nowo-task-column-set-save'));
        self::assertCount(1, $crawler->filter('dialog#nowo-task-column-set-apply'));

        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/panel');
        self::assertCount(0, $crawler->filter('.nowo-task-detail__toolbar'));
        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId());
        self::assertCount(1, $crawler->filter('.nowo-task-detail__toolbar'), 'the page keeps it');
    }

    public function testByDefaultTheChromeIsThereAndTheViewsAreTabs(): void
    {
        $this->boot([]);
        $board   = $this->board();
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId());
        self::assertCount(1, $crawler->filter('[data-nowo-ui-region="header"]'));
        self::assertCount(3, $crawler->filter('[data-nowo-ui-region="area-nav"] a'));
        self::assertCount(1, $crawler->filter('.nowo-task-board__toolbar .nowo-task-column-sets__save[data-nowo-task-board-open="column-set-save"]'));
    }

    public function testAnEmojiPickerIsTheIconFieldAndTheConstraintsStay(): void
    {
        $this->boot(['board' => ['icon' => true, 'icon_form_type' => EmojiPickerType::class]]);
        $crawler = $this->client->request('GET', self::BASE);
        self::assertCount(1, $crawler->filter('dialog [data-nowo-ui-emoji-picker] input[name="task_board_form[icon]"], dialog input[name="task_board_form[icon]"]'));

        $post = static fn (string $icon): array => ['task_board_form' => ['name' => 'With icon', 'icon' => $icon, '_token' => PredictableCsrfTokenManager::value('task_board_form')]];
        $this->client->request('POST', self::BASE . '/create', $post('not an emoji'));
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(TaskBoard::class)->findAll());

        $this->client->request('POST', self::BASE . '/create', $post('🚀'));
        self::assertResponseRedirects();
        $board = $this->em->getRepository(TaskBoard::class)->findOneBy(['name' => 'With icon']);
        self::assertInstanceOf(TaskBoard::class, $board);
        self::assertSame('🚀', $board->getIcon());
    }

    public function testTheImportPagePrintsNoRawFormKeys(): void
    {
        $this->boot([]);
        $crawler = $this->client->request('GET', self::BASE . '/board/' . $this->board()->getId() . '/import');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('task_import_form.', $crawler->filter('[data-nowo-ui-page="form"]')->text());
    }

    public function testTheHostsWayBackIsAHeaderActionOfTheIndex(): void
    {
        $this->boot(['dashboard_route' => 'nowo_task_board_index']);
        $crawler = $this->client->request('GET', self::BASE);
        self::assertStringContainsString('Back', $crawler->filter('[data-nowo-ui-region="header"]')->text());
    }

    private function import(TaskBoard $board): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tb');
        self::assertIsString($file);
        file_put_contents($file, self::JIRA);
        $this->client->request('POST', self::BASE . '/board/' . $board->getId() . '/import', [
            'task_import_form' => ['source' => 'jira_csv', 'createMissingColumns' => '1', '_token' => PredictableCsrfTokenManager::value('task_import_form')],
        ], ['task_import_form' => ['file' => new UploadedFile($file, 'tasks.csv', 'text/csv', null, true)]]);
    }
}
