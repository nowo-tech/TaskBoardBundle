<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function fwrite;
use function sprintf;
use function strlen;
use function substr_count;

use const STDERR;

/**
 * Since 2.31.0 (owner rule 2026-11-23, every dialog loads its content on demand): the task page renders no dialog form;
 * each dialog is the GET of its write route — the fragment for `X-Nowo-Dialog: 1`, a page with the same form otherwise —
 * with the write's own access decision; an invalid post from the dialog is answered in place (422), a saved one with
 * 204 + `X-Nowo-Redirect`. The archived tasks of the list share one Restore form.
 */
final class LazyDialogsTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    private const DIALOG = ['HTTP_X_NOWO_DIALOG' => '1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html'];

    public function testTheTaskPageRendersNoDialogFormWhateverTheRows(): void
    {
        $task = $this->crowdedTask(50);
        $this->login($this->owner);

        $html = $this->html('GET', '/task/' . $task->getId());
        fwrite(STDERR, sprintf("\n[lazy-dialogs] task page, 50 members + 50 links: %d bytes, %d forms, %d dialogs\n", strlen($html), substr_count($html, '<form'), substr_count($html, '<dialog')));

        foreach (['nowo-task-members-add-', 'nowo-task-board-priority-form-', 'nowo-task-time-form-', 'nowo-task-dependency-form-', 'nowo-task-subtask-import-form-', 'nowo-task-link-form'] as $id) {
            self::assertStringNotContainsString('id="' . $id, $html);
        }
        self::assertStringNotContainsString('nowo-task-members__remove', $html, 'no remove form per member');
        // The task form, the inline subtask add and the CSRF-only remove forms (time, dependency): never one per row.
        self::assertLessThan(10, substr_count($html, '<form'));
        $crawler = $this->client->getCrawler();
        $dialog  = $crawler->filter('dialog[data-testid="nowo-task-dialog"]');
        self::assertCount(1, $dialog);
        self::assertSame('', trim($dialog->filter('[data-nowo-ui-dialog-body]')->text()));
        $members = $crawler->filter('[data-testid="nowo-task-members-open"]');
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/member', $members->attr('href'));
        self::assertSame($dialog->attr('id'), $members->attr('data-nowo-ui-dialog-open'));
        self::assertNotNull($members->attr('data-nowo-ui-dialog-form'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/priority', $crawler->filter('[data-testid="nowo-task-priority-open"]')->attr('href'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/time', $crawler->filter('[data-testid="nowo-task-time-open"]')->attr('href'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/dependency', $crawler->filter('[data-testid="nowo-task-dependency-open"]')->attr('href'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/import', $crawler->filter('.nowo-task-subtask-import__open')->attr('href'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/link', $crawler->filter('[data-testid="nowo-task-link-add"]')->attr('href'));
        self::assertCount(50, $crawler->filter('a.nowo-task-detail__link-edit'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function dialogs(): iterable
    {
        yield 'members' => ['/member', 'form[id^="nowo-task-members-add-"]'];
        yield 'priority' => ['/priority', 'form[id^="nowo-task-board-priority-form-"]'];
        yield 'import subtasks' => ['/import', 'form[id^="nowo-task-subtask-import-form-"]'];
        yield 'log time' => ['/time', 'form[id^="nowo-task-time-form-"]'];
        yield 'add dependency' => ['/dependency', 'form[id^="nowo-task-dependency-form-"]'];
        yield 'add link' => ['/link', 'form#nowo-task-link-form'];
    }

    #[DataProvider('dialogs')]
    public function testEachDialogIsAFragmentForTheDialogAndAPageOtherwise(string $path, string $form): void
    {
        $task = $this->crowdedTask(3);
        $this->login($this->owner);

        $fragment = $this->html('GET', '/task/' . $task->getId() . $path, server: self::DIALOG);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('X-Nowo-Dialog', (string) $this->client->getResponse()->headers->get('Vary'));
        self::assertStringNotContainsString('<html', $fragment);
        self::assertSame(0, $this->client->getCrawler()->filter('dialog ' . $form)->count(), 'the fragment is the body, not a dialog');
        self::assertCount(1, $this->client->getCrawler()->filter($form));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-nowo-ui-dialog-actions]'));
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('[autofocus]')->count(), 'the first field takes the focus');

        $page = $this->html('GET', '/task/' . $task->getId() . $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<html', $page);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="nowo-task-dialog-page"] ' . $form));
        // Without JavaScript, Cancel is a link back to the task.
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('[data-testid="nowo-task-dialog-page"] a[href="' . self::BASE . '/task/' . $task->getId() . '"]')->count());
    }

    public function testTheMembersDialogHoldsTheRemoveFormsAndKeepsTheTargetPath(): void
    {
        $task = $this->crowdedTask(3);
        $this->login($this->owner);

        $this->html('GET', '/task/' . $task->getId() . '/member?_target_path=/tools/task-board/board/' . $task->getBoard()->getId(), server: self::DIALOG);
        $remove = $this->client->getCrawler()->filter('form.nowo-task-members__remove');
        self::assertCount(3, $remove);
        self::assertStringContainsString('_target_path=', (string) $remove->first()->attr('action'));
        // An open redirect is not carried.
        $this->html('GET', '/task/' . $task->getId() . '/member?_target_path=//evil.example', server: self::DIALOG);
        self::assertStringNotContainsString('evil', (string) $this->client->getCrawler()->filter('form.nowo-task-members__remove')->first()->attr('action'));
    }

    public function testTheEditLinkDialogIsFilledOnTheServer(): void
    {
        $task = $this->crowdedTask(1);
        $link = $task->getLinks()->first();
        self::assertInstanceOf(TaskLink::class, $link);
        $this->login($this->owner);

        $this->html('GET', '/task/' . $task->getId() . '/link/' . $link->getId(), server: self::DIALOG);
        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertSame('https://example.test/0', $crawler->filter('input[name="task_link_form[url]"]')->attr('value'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/link/' . $link->getId(), $crawler->filter('form#nowo-task-link-form')->attr('action'));
        self::assertSame(self::BASE . '/task/' . $task->getId() . '/link/' . $link->getId() . '/remove', $crawler->filter('form#nowo-task-link-delete-form')->attr('action'));
        self::assertNotNull($crawler->filter('form#nowo-task-link-delete-form')->attr('data-nowo-ui-remote-form-native'));
        self::assertNull($crawler->filter('button[form="nowo-task-link-delete-form"]')->attr('hidden'));

        $this->client->request('GET', self::BASE . '/task/' . $task->getId() . '/link/missing', server: self::DIALOG);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInvalidPostFromTheDialogIsAnsweredInPlace(): void
    {
        $task = $this->crowdedTask(1);
        $this->login($this->owner);
        $id = $task->getId();

        $this->html('POST', '/task/' . $id . '/time', ['task_time_entry' => ['minutes' => '-5', 'loggedAt' => '2026-11-04', 'note' => '', '_token' => PredictableCsrfTokenManager::value('task_time_entry')]], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('-5', $this->client->getCrawler()->filter('input[name="task_time_entry[minutes]"]')->attr('value'));

        $this->html('POST', '/task/' . $id . '/link', ['task_link_form' => ['linkType' => 'url', 'url' => 'not a url', '_token' => PredictableCsrfTokenManager::value('task_link_form')]], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('form#nowo-task-link-form'));

        $link = $task->getLinks()->first();
        self::assertInstanceOf(TaskLink::class, $link);
        $this->html('POST', '/task/' . $id . '/link/' . $link->getId(), ['task_link_form' => ['linkType' => 'url', 'url' => '', '_token' => PredictableCsrfTokenManager::value('task_link_form')]], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('form#nowo-task-link-delete-form'));

        $this->html('POST', '/task/' . $id . '/member', ['task_member_form' => ['user' => 'nobody', 'memberRole' => 'assignee', '_token' => PredictableCsrfTokenManager::value('task_member_form')]], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('form[id^="nowo-task-members-add-"]'));

        $this->html('POST', '/task/' . $id . '/dependency', ['task_dependency' => ['type' => 'blocks', 'taskId' => 'missing', '_token' => PredictableCsrfTokenManager::value('task_dependency')]], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->client->getCrawler()->filter('form[id^="nowo-task-dependency-form-"]'));

        $this->client->request('POST', self::BASE . '/task/' . $id . '/import', ['task_import_form' => ['source' => 'taskboard_csv', '_token' => PredictableCsrfTokenManager::value('task_import_form')]], server: self::DIALOG);
        self::assertResponseStatusCodeSame(422);

        // Without the dialog header the 2.30 answers are unchanged: a flash and a redirect.
        $this->client->request('POST', self::BASE . '/task/' . $id . '/time', ['task_time_entry' => ['minutes' => '-5', 'loggedAt' => '2026-11-04', 'note' => '', '_token' => PredictableCsrfTokenManager::value('task_time_entry')]]);
        self::assertResponseRedirects(self::BASE . '/task/' . $id);
        self::assertSame(0, $this->em->getRepository(TaskTimeEntry::class)->count([]));
    }

    public function testADependencyRefusedByTheRulesIsAnsweredInPlace(): void
    {
        $task = $this->crowdedTask(1);
        $this->login($this->owner);

        $this->html('POST', '/task/' . $task->getId() . '/dependency', ['task_dependency' => ['type' => 'blocks', 'taskId' => $task->getId(), '_token' => PredictableCsrfTokenManager::value('task_dependency')]], self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('form[id^="nowo-task-dependency-form-"] .invalid-feedback, form[id^="nowo-task-dependency-form-"] ul li, form[id^="nowo-task-dependency-form-"] [class*="error"]')->count());
    }

    public function testASavedPostFromTheDialogAnswers204AndTheRedirect(): void
    {
        $task = $this->crowdedTask(1);
        $this->login($this->owner);
        $id = $task->getId();

        $this->client->request('POST', self::BASE . '/task/' . $id . '/time', ['task_time_entry' => ['minutes' => '15', 'loggedAt' => '2026-11-04', 'note' => '', '_token' => PredictableCsrfTokenManager::value('task_time_entry')]], server: self::DIALOG);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(self::BASE . '/task/' . $id, $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
        self::assertSame(1, $this->em->getRepository(TaskTimeEntry::class)->count([]));

        $target = self::BASE . '/board/' . $task->getBoard()->getId();
        $this->client->request('POST', self::BASE . '/task/' . $id . '/priority?_target_path=' . $target, ['priority' => 'high', '_token' => PredictableCsrfTokenManager::value('task_board_task_priority')], server: self::DIALOG);
        self::assertResponseStatusCodeSame(204);
        self::assertSame($target, $this->client->getResponse()->headers->get('X-Nowo-Redirect'));
    }

    /** TB-L1: a `_target_path` with dot segments falls back to the task URL; the committed write is never a 500. */
    public function testADotSegmentTargetPathFallsBackToTheTaskUrlAfterTheWrite(): void
    {
        $task = $this->crowdedTask(1);
        $this->login($this->owner);
        $id = $task->getId();

        foreach (['/tools/task-board/../x', '/tools/task-board/%2e%2e/x', '/./x'] as $bad) {
            $before = $this->em->getRepository(TaskTimeEntry::class)->count([]);
            $this->client->request('POST', self::BASE . '/task/' . $id . '/time?_target_path=' . $bad, ['task_time_entry' => ['minutes' => '15', 'loggedAt' => '2026-11-04', 'note' => '', '_token' => PredictableCsrfTokenManager::value('task_time_entry')]], server: self::DIALOG);
            self::assertResponseStatusCodeSame(204, $bad);
            self::assertSame(self::BASE . '/task/' . $id, $this->client->getResponse()->headers->get('X-Nowo-Redirect'), $bad);
            self::assertSame($before + 1, $this->em->getRepository(TaskTimeEntry::class)->count([]), $bad);

            $this->client->request('POST', self::BASE . '/task/' . $id . '/link?_target_path=' . $bad, ['task_link_form' => ['linkType' => 'url', 'url' => 'https://example.test/new', '_token' => PredictableCsrfTokenManager::value('task_link_form')]], server: self::DIALOG);
            self::assertResponseStatusCodeSame(204, $bad);
            self::assertSame(self::BASE . '/task/' . $id, $this->client->getResponse()->headers->get('X-Nowo-Redirect'), $bad);
        }
    }

    public function testTheDialogsRefuseAsTheirWritesDo(): void
    {
        $task = $this->crowdedTask(1);
        $id   = $task->getId();
        $link = $task->getLinks()->first();
        self::assertInstanceOf(TaskLink::class, $link);
        $paths = ['/member', '/priority', '/import', '/time', '/dependency', '/link', '/link/' . $link->getId()];

        // Someone who may not see the board: 403 on the dialog as on the write.
        $this->login($this->user('stranger@example.com'));
        foreach ($paths as $path) {
            $this->client->request('GET', self::BASE . '/task/' . $id . $path, server: self::DIALOG);
            self::assertResponseStatusCodeSame(403, $path);
            $this->client->request('GET', self::BASE . '/task/' . $id . $path);
            self::assertResponseStatusCodeSame(403, $path);
        }
        $this->client->request('POST', self::BASE . '/task/' . $id . '/time', ['task_time_entry' => ['minutes' => '15', 'loggedAt' => '2026-11-04', '_token' => PredictableCsrfTokenManager::value('task_time_entry')]], server: self::DIALOG);
        self::assertResponseStatusCodeSame(403);

        // A read-only task: the page opens, its dialogs and writes do not.
        $this->login($this->owner);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->markReadOnly();
        });
        $this->client->request('GET', self::BASE . '/task/' . $id);
        self::assertResponseIsSuccessful();
        foreach ($paths as $path) {
            $this->client->request('GET', self::BASE . '/task/' . $id . $path, server: self::DIALOG);
            self::assertResponseStatusCodeSame(403, $path);
        }

        // An unknown task: 404.
        $this->client->request('GET', self::BASE . '/task/missing/member', server: self::DIALOG);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheArchivedTasksShareOneRestoreForm(): void
    {
        $board = $this->board();
        for ($i = 0; $i < 50; ++$i) {
            $task = $this->task($board, 'Archived ' . $i, position: $i);
            $task->archive('user');
        }
        $this->em->flush();
        $this->login($this->owner);

        $html = $this->html('GET', '/board/' . $board->getId() . '/list');
        fwrite(STDERR, sprintf("\n[lazy-dialogs] list, 50 archived tasks: %d bytes, %d forms\n", strlen($html), substr_count($html, '<form')));
        $crawler = $this->client->getCrawler();
        self::assertCount(50, $crawler->filter('button[data-testid="nowo-task-restore"][form="nowo-task-restore-form"]'));
        self::assertCount(1, $crawler->filter('form#nowo-task-restore-form'));
        self::assertLessThan(10, substr_count($html, '<form'));

        // The shared form still restores the task its button names.
        $first = $crawler->filter('button[data-testid="nowo-task-restore"]')->first();
        $this->client->request('POST', (string) $first->attr('formaction'), ['_token' => PredictableCsrfTokenManager::value('task_board_task_restore')]);
        self::assertResponseRedirects(self::BASE . '/board/' . $board->getId() . '/list');
        $this->client->request('POST', (string) $first->attr('formaction'), ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A task with `$rows` members (other users) and `$rows` URL links.
     */
    private function crowdedTask(int $rows): Task
    {
        $board = $this->board();
        $task  = $this->task($board, 'Crowded');
        $this->task($board, 'Other', position: 1);
        for ($i = 0; $i < $rows; ++$i) {
            $task->addMember(new TaskMember($task, $this->user('member' . $i . '@example.com'), TaskMemberRole::Watcher));
            $task->addLink(new TaskLink($task, TaskLinkType::Url, 'https://example.test/' . $i, 'Link ' . $i));
        }
        $this->em->flush();

        return $task;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, string> $server
     */
    private function html(string $method, string $path, array $parameters = [], array $server = []): string
    {
        $this->client->request($method, self::BASE . $path, $parameters, server: $server);

        return (string) $this->client->getResponse()->getContent();
    }
}
