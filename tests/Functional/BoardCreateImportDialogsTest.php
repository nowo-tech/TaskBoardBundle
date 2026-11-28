<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Since 3.6.0 (owner rule 2026-11-23, every dialog loads its content on demand): New board and Import from file are the GET
 * of `board_create` / `board_import` — a fragment for UiKit's remote dialog (`X-Nowo-Dialog: 1`) with the gates of their
 * POST; an invalid post answers 422 with the form, a saved one 204 + `X-Nowo-Redirect`.
 */
final class BoardCreateImportDialogsTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private const DIALOG = ['HTTP_X_NOWO_DIALOG' => '1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html'];

    public function testTheNewBoardDialogIsAFragmentAndAPlainGetOpensTheIndex(): void
    {
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . '/create', server: self::DIALOG);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('X-Nowo-Dialog', (string) $this->client->getResponse()->headers->get('Vary'));
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertSame(self::BASE . '/create', $crawler->filter('form#nowo-task-board-create-form')->attr('action'));
        self::assertCount(1, $crawler->filter('[data-nowo-ui-dialog-actions] button[type="submit"]'));

        $this->client->request('GET', self::BASE . '/create');
        self::assertResponseRedirects(self::BASE . '?new=1');
    }

    public function testAnInvalidNewBoardIsAnsweredInPlaceAndAValidOneRedirectsTheDialog(): void
    {
        $this->login($this->owner);

        $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => '', '_token' => PredictableCsrfTokenManager::value('task_board_form')]], server: self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('X-Nowo-Dialog', (string) $this->client->getResponse()->headers->get('Vary'));
        self::assertCount(1, $this->client->getCrawler()->filter('form#nowo-task-board-create-form'));
        self::assertSame(0, $this->em->getRepository(TaskBoard::class)->count([]));

        $this->client->request('POST', self::BASE . '/create', ['task_board_form' => ['name' => 'From the dialog', '_token' => PredictableCsrfTokenManager::value('task_board_form')]], server: self::DIALOG);
        self::assertResponseStatusCodeSame(204);
        self::assertStringContainsString(self::BASE . '/board/', (string) $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertSame(1, $this->em->getRepository(TaskBoard::class)->count([]));
    }

    public function testTheNewBoardDialogOfAnotherTenantIsRefusedAsThePostIs(): void
    {
        self::$taskBoardConfig = [
            'security' => ['access_checker' => 'app.tenant_create_checker'],
            'tenancy'  => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme/create', server: self::DIALOG);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/t/other/create', server: self::DIALOG);
        self::assertResponseStatusCodeSame(403, 'the create check of the tenant, as for the POST');
        $this->client->request('POST', '/t/other/create', ['task_board_form' => ['name' => 'X', '_token' => PredictableCsrfTokenManager::value('task_board_form')]], server: self::DIALOG);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheImportDialogIsAFragmentWithTheAccessOfTheImport(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $other = $this->board('Other');
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import', server: self::DIALOG);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('X-Nowo-Dialog', (string) $this->client->getResponse()->headers->get('Vary'));
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('form#nowo-task-board-import-form input[type="file"]'));
        self::assertCount(1, $crawler->filter('[data-nowo-ui-dialog-actions] button[form="nowo-task-board-import-form"]'));

        // Without the dialog header the import page is unchanged.
        $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import');
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());

        // Another person's board id and an unknown one: 404 / 403 as the page.
        $this->client->request('GET', self::BASE . '/board/missing/import', server: self::DIALOG);
        self::assertResponseStatusCodeSame(404);

        $stranger = $this->user('stranger@example.com');
        $this->login($stranger);
        $this->client->request('GET', self::BASE . '/board/' . $other->getId() . '/import', server: self::DIALOG);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheImportDialogRefusesWhenImportIsDenied(): void
    {
        $board = $this->board();
        $this->login($this->owner);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_IMPORT_TASKS) {
                $event->deny();
            }
        });

        $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import', server: self::DIALOG);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnInvalidImportIsAnsweredInPlaceAndAFinishedOneRedirectsTheDialog(): void
    {
        $board = $this->board(columns: ['To do', 'Done']);
        $this->login($this->owner);
        $url = self::BASE . '/board/' . $board->getId() . '/import';

        $this->client->request('POST', $url, ['task_import_form' => ['source' => 'jira_csv', '_token' => PredictableCsrfTokenManager::value('task_import_form')]], [], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('form#nowo-task-board-import-form'));

        $path = (string) tempnam(sys_get_temp_dir(), 'tb-import');
        file_put_contents($path, "Issue key,Summary,Status\nPRJ-1,Spec,To do\n");
        $this->client->request('POST', $url, ['task_import_form' => ['source' => 'jira_csv', 'createMissingColumns' => '1', '_token' => PredictableCsrfTokenManager::value('task_import_form')]], ['task_import_form' => ['file' => new UploadedFile($path, 'tasks.csv', null, null, true)]], self::DIALOG);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(self::BASE . '/board/' . $board->getId(), $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertSame(1, $this->em->getRepository(Task::class)->count(['title' => 'Spec']));
    }
}
