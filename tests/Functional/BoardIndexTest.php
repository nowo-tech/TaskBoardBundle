<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\PreferredViewResolver;
use Nowo\TaskBoardBundle\Tests\Functional\App\TenantCreateChecker;

/**
 * Since 1.24 (Beacon audit KIT-67): the board index as a list page with a New board dialog, the tenant on the create
 * check, an invalid create answered 422 with its values and errors, and the user's default board view.
 */
final class BoardIndexTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    /** BrowserKit sends the previous page as Referer; an empty one is "no Referer". */
    private const NO_REFERER = ['HTTP_REFERER' => ''];

    public function testTheIndexListsTheBoardsWithTheirViewsAndANewBoardDialog(): void
    {
        $board = $this->board('Roadmap');
        $board->setDescription('Next quarter');
        $this->em->flush();
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();

        $item = $crawler->filter('[data-testid="nowo-task-board-list"] [data-testid="nowo-task-board-list-item"]');
        self::assertCount(1, $item);
        self::assertStringContainsString('Next quarter', $item->text());
        self::assertSame('Views of Roadmap', $item->filter('[role="group"]')->attr('aria-label'));
        $id = $board->getId();
        self::assertSame(self::BASE . '/board/' . $id . '?view=board', $item->filter('[data-testid="nowo-task-board-view-board"]')->attr('href'));
        self::assertSame(self::BASE . '/board/' . $id . '/list', $item->filter('[data-testid="nowo-task-board-view-list"]')->attr('href'));
        self::assertSame(self::BASE . '/board/' . $id . '/gantt', $item->filter('[data-testid="nowo-task-board-view-gantt"]')->attr('href'));
        self::assertSame('Open Roadmap as a Gantt chart', $item->filter('[data-testid="nowo-task-board-view-gantt"]')->attr('aria-label'));

        // New board opens the dialog; the dialog holds the one create form, closed until asked.
        self::assertSame('board-create', $crawler->filter('[data-testid="nowo-task-board-new"]')->attr('data-dialog'));
        $dialog = $crawler->filter('dialog[data-testid="nowo-task-board-create-dialog"]');
        self::assertCount(1, $dialog);
        self::assertNull($dialog->attr('data-open-on-connect'));
        self::assertSame(self::BASE . '/create', $dialog->filter('form')->attr('action'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-board-index-empty"]'));

        // ?new=1 opens it on load (a host's "New board" link).
        $crawler = $this->client->request('GET', self::BASE . '?new=1');
        self::assertSame('', $crawler->filter('dialog[data-testid="nowo-task-board-create-dialog"]')->attr('data-open-on-connect'));
    }

    public function testAnEmptyIndexShowsTheEmptyStateAndHidesCreationFromWhoMayNotCreate(): void
    {
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE);
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-index-empty"] [data-testid="nowo-task-board-new-empty"]'));

        self::$taskBoardConfig = ['security' => ['create_roles' => ['ROLE_ADMIN']]];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="nowo-task-board-index-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="nowo-task-board-new"], [data-testid="nowo-task-board-new-empty"], dialog'));
        // ?new=1 does not open a dialog the user cannot use.
        $this->client->request('GET', self::BASE . '?new=1');
        self::assertSelectorNotExists('dialog');
    }

    public function testAnInvalidCreateAnswers422WithTheDialogOpenAndTheValuesAndErrorsKept(): void
    {
        $this->login($this->owner);
        $crawler = $this->client->request('POST', self::BASE . '/create', ['task_board_form' => [
            'name'        => '',
            'description' => 'Kept <b>text</b>',
            '_token'      => PredictableCsrfTokenManager::value('task_board_form'),
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $dialog = $crawler->filter('dialog[data-testid="nowo-task-board-create-dialog"]');
        self::assertSame('', $dialog->attr('data-open-on-connect'));
        self::assertSame('Kept <b>text</b>', $dialog->filter('textarea[name="task_board_form[description]"]')->text());
        self::assertGreaterThan(0, $dialog->filter('.invalid-feedback, .form-error-message, ul li')->count(), 'the name error is shown');
        self::assertSelectorTextContains('[role="alert"]', 'The board was not created');
        self::assertCount(0, $this->em->getRepository(TaskBoard::class)->findAll());

        // The <noscript> copy of the form posts to the same route with its own field ids.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('id="nojs-task_board_form_name"', $html);

        // A POST without the form is sent back to the index; a valid one creates the board.
        $this->client->request('POST', self::BASE . '/create');
        self::assertResponseRedirects(self::BASE);
        $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => 'Fresh', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        self::assertResponseRedirects();
        self::assertStringStartsWith(self::BASE . '/board/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnInvalidCreateFromAHostDialogAnswersOnlyTheForm(): void
    {
        self::$taskBoardConfig = ['board' => ['create_dialog_header' => 'X-Host-Dialog']];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);

        $crawler = $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => '', 'slug' => 'kept-slug', '_token' => PredictableCsrfTokenManager::value('task_board_form')]], [], ['HTTP_X_HOST_DIALOG' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('X-Host-Dialog', (string) $this->client->getResponse()->headers->get('Vary'));
        self::assertCount(0, $crawler->filter('dialog, h1'), 'only the form, for the host dialog');
        self::assertSame('kept-slug', $crawler->filter('[data-testid="nowo-task-board-create-form"] input[name="task_board_form[slug]"]')->attr('value'));

        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        // Without the header: the index page, varying on the header too.
        $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => '', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('X-Host-Dialog', (string) $this->client->getResponse()->headers->get('Vary'));
        self::assertSelectorExists('dialog[data-testid="nowo-task-board-create-dialog"]');
    }

    public function testTheCreateCheckGetsTheTenantOfTheRequest(): void
    {
        self::$taskBoardConfig = [
            'security' => ['access_checker' => 'app.tenant_create_checker'],
            'tenancy'  => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);
        $checker = self::getContainer()->get('app.tenant_create_checker');
        self::assertInstanceOf(TenantCreateChecker::class, $checker);

        $this->client->request('GET', '/t/acme');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-board-new-empty"]');
        $this->client->request('GET', '/t/other');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="nowo-task-board-new-empty"]');

        $this->client->request('POST', '/t/other/create', ['task_board_form' => ['name' => 'Denied', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/t/acme/create', ['task_board_form' => ['name' => 'Allowed', '_token' => PredictableCsrfTokenManager::value('task_board_form')]]);
        self::assertResponseRedirects();
        self::assertCount(1, $this->em->getRepository(TaskBoard::class)->findBy(['tenantKey' => 'acme']));
        self::assertSame(['acme', 'other', 'other', 'acme'], $checker->asked);
    }

    public function testTheBoardRouteOpensTheUsersDefaultView(): void
    {
        self::$taskBoardConfig = ['board' => ['default_view_resolver' => 'app.default_view']];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board();
        $this->login($this->owner);
        $resolver = self::getContainer()->get('app.default_view');
        self::assertInstanceOf(PreferredViewResolver::class, $resolver);
        $path = self::BASE . '/board/' . $board->getId();

        // No preference, or the board view: the board.
        foreach ([null, 'board', 'calendar'] as $view) {
            $resolver->view = $view;
            $this->client->request('GET', $path, [], [], self::NO_REFERER);
            self::assertResponseIsSuccessful((string) $view);
        }

        $resolver->view = 'list';
        $this->client->request('GET', $path . '?q=x', [], [], self::NO_REFERER);
        self::assertResponseRedirects($path . '/list?q=x');
        $resolver->view = 'gantt';
        $this->client->request('GET', $path, [], [], self::NO_REFERER);
        self::assertResponseRedirects($path . '/gantt');

        // Asked for explicitly: ?view=board, an XHR request or a link from a page of the same board.
        $this->client->request('GET', $path . '?view=board', [], [], self::NO_REFERER);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $path, [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] + self::NO_REFERER);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $path, [], [], ['HTTP_REFERER' => 'http://localhost' . $path . '/gantt']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $path, [], [], ['HTTP_REFERER' => $path]);
        self::assertResponseIsSuccessful();
        // Another site or another page still gets the default view.
        $this->client->request('GET', $path, [], [], ['HTTP_REFERER' => 'https://elsewhere.test' . $path . '/gantt']);
        self::assertResponseRedirects($path . '/gantt');
        $this->client->request('GET', $path, [], [], ['HTTP_REFERER' => 'http://localhost' . self::BASE]);
        self::assertResponseRedirects($path . '/gantt');
        $this->client->request('GET', $path, [], [], ['HTTP_REFERER' => 'http://[']);
        self::assertResponseRedirects($path . '/gantt');

        // The list and Gantt pages link back to the board view explicitly.
        $crawler = $this->client->request('GET', $path . '/list');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="' . $path . '?view=board"]'));
    }

    /**
     * Audit EX-L1 (probes of the 1.24.0 review): the query never retargets the redirect.
     */
    public function testTheDefaultViewRedirectDropsRouteVariablesAndReservedKeys(): void
    {
        self::$taskBoardConfig = [
            'board'   => ['default_view_resolver' => 'app.default_view'],
            'tenancy' => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
        self::ensureKernelShutdown();
        $this->setUp();
        $board = $this->board('A', tenantKey: 'acme');
        $this->login($this->owner);
        $resolver = self::getContainer()->get('app.default_view');
        self::assertInstanceOf(PreferredViewResolver::class, $resolver);
        $resolver->view = 'list';
        $path           = '/t/acme/board/' . $board->getId();

        $cases = [
            '?tenantSlug=other'             => $path . '/list',
            '?tenantSlug=bad/..%2F%2Fevil'  => $path . '/list',
            '?_fragment=x%0d%0ay'           => $path . '/list',
            '?_locale=xx'                   => $path . '/list',
            '?boardId=999&board=1&q=a%20b'  => $path . '/list?q=a%20b',
            '?a%5B0%5D=1&next=//evil.test/' => $path . '/list?a%5B0%5D=1&next=%2F%2Fevil.test%2F',
        ];
        foreach ($cases as $query => $location) {
            $this->client->request('GET', $path . $query, [], [], self::NO_REFERER);
            self::assertResponseRedirects($location, 302, $query);
        }
    }

    /**
     * Audit EX-I2: single-board tenancy creates the tenant's first board only for a user the create check accepts.
     */
    public function testASingleBoardTenantCreatesItsBoardOnlyForWhoMayCreate(): void
    {
        self::$taskBoardConfig = [
            'security' => ['access_checker' => 'app.tenant_create_checker'],
            'tenancy'  => ['enabled' => true, 'single_board' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);

        $this->client->request('GET', '/t/other');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="nowo-task-board-index-empty"]');
        self::assertCount(0, $this->em->getRepository(TaskBoard::class)->findAll());

        $this->client->request('GET', '/t/acme');
        self::assertResponseRedirects();
        self::assertCount(1, $this->em->getRepository(TaskBoard::class)->findBy(['tenantKey' => 'acme']));

        // An existing board opens for everyone.
        $owner = $this->em->getRepository(AppUser::class)->findOneBy(['email' => 'owner@example.com']);
        self::assertInstanceOf(AppUser::class, $owner);
        $this->board('Other board', creator: $owner, tenantKey: 'other');
        $this->client->request('GET', '/t/other');
        self::assertResponseRedirects();
    }
}
