<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Tests\Functional\App\OwnerOnlyAssigneeProvider;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_filter;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Board and task writes through the HTTP layer (real forms, CSRF, Doctrine): each one persists, redirects and logs.
 */
final class ManageWritesTest extends FunctionalTestCase
{
    private const BASE = '/tools/task-board';

    public function testColumnsAreCreatedAndUpdated(): void
    {
        $board = $this->board(columns: ['To do']);
        $this->login($this->owner);

        $this->post('/board/' . $board->getId() . '/column', ['board_column_form' => ['name' => 'Review', 'color' => '#3b82f6', '_token' => $this->csrf('board_column_form')]]);
        self::assertResponseRedirects(self::BASE . '/board/' . $board->getId());

        $column = $this->em->getRepository(BoardColumn::class)->findOneBy(['name' => 'Review']);
        self::assertInstanceOf(BoardColumn::class, $column);
        $this->post('/board/' . $board->getId() . '/column/' . $column->getId(), ['board_column_form' => ['name' => 'Code review', 'color' => '#22c55e', '_token' => $this->csrf('board_column_form')]]);
        self::assertResponseRedirects(self::BASE . '/board/' . $board->getId());

        $this->em->clear();
        self::assertSame('Code review', $this->em->find(BoardColumn::class, $column->getId())?->getName());
        self::assertSame(['column_create', 'column_update'], $this->writes());
    }

    public function testABadCsrfTokenIsRefusedAndLogged(): void
    {
        $board = $this->board();
        $this->login($this->owner);

        $this->post('/board/' . $board->getId() . '/columns/reorder', ['_token' => 'forged', 'columnOrder' => []]);

        self::assertResponseStatusCodeSame(403);
        $refused = array_filter($this->logger()->records, static fn (array $record): bool => $record['message'] === 'TaskBoard write refused: {reason}');
        self::assertCount(1, $refused);
        self::assertSame('nowo_task_board_column_reorder', [...$refused][0]['context']['route']);
    }

    public function testABoardImportCreatesTasksAndReportsWarnings(): void
    {
        $board = $this->board(columns: ['To do']);
        $this->login($this->owner);

        $this->client->request('GET', self::BASE . '/board/' . $board->getId() . '/import');
        self::assertResponseIsSuccessful();

        $csv = "title,status,priority\nWrite the spec,To do,high\nShip,Unknown,normal\n";
        $this->post('/board/' . $board->getId() . '/import', ['task_import_form' => ['source' => 'taskboard_csv', '_token' => $this->csrf('task_import_form')]], ['task_import_form' => ['file' => $this->upload($csv)]]);
        self::assertResponseRedirects(self::BASE . '/board/' . $board->getId());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Write the spec');
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $board]));
    }

    public function testImportingTheSameFileAgainUpdatesInsteadOfDuplicating(): void
    {
        $board = $this->board(columns: ['To do']);
        $this->login($this->owner);
        $csv = "id,title,status\na,Write the spec,To do\nb,Ship,To do\n";

        $this->post('/board/' . $board->getId() . '/import', ['task_import_form' => ['source' => 'taskboard_csv', '_token' => $this->csrf('task_import_form')]], ['task_import_form' => ['file' => $this->upload($csv)]]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Import done: 2 created, 0 updated, 0 unchanged, 0 skipped');

        // A post with the removed (deprecated) skipExisting field is still accepted.
        $this->post('/board/' . $board->getId() . '/import', ['task_import_form' => ['source' => 'taskboard_csv', 'skipExisting' => '1', '_token' => $this->csrf('task_import_form')]], ['task_import_form' => ['file' => $this->upload($csv)]]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Import done: 0 created, 0 updated, 2 unchanged, 0 skipped');
        self::assertCount(2, $this->em->getRepository(Task::class)->findBy(['board' => $board]));
    }

    public function testAnImportThatFailsFlashesTheErrors(): void
    {
        $board = $this->board(columns: ['To do']);
        $this->login($this->owner);

        $this->post('/board/' . $board->getId() . '/import', ['task_import_form' => ['source' => 'clickup_json', '_token' => $this->csrf('task_import_form')]], ['task_import_form' => ['file' => $this->upload('{oops', 'export.json')]]);

        self::assertResponseRedirects(self::BASE . '/board/' . $board->getId());
        self::assertSame([], $this->em->getRepository(Task::class)->findBy(['board' => $board]));
    }

    public function testATaskAdvancesToThePreviousNextOrAGivenColumn(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Move me', 1);
        $this->login($this->owner);
        $token = PredictableCsrfTokenManager::value('task_board_task_advance');

        $this->post('/task/' . $task->getId() . '/advance', ['_token' => $token, 'action' => 'next']);
        self::assertSame('Done', $this->reloadTask($task)->getColumn()?->getName());
        $this->post('/task/' . $task->getId() . '/advance', ['_token' => $token, 'action' => 'previous']);
        self::assertSame('Doing', $this->reloadTask($task)->getColumn()?->getName());
        $first = $board->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $first);
        $this->post('/task/' . $task->getId() . '/advance', ['_token' => $token, 'columnId' => $first->getId()]);
        self::assertSame('To do', $this->reloadTask($task)->getColumn()?->getName());
        self::assertSame(['task_advance', 'task_advance', 'task_advance'], $this->writes());
    }

    public function testLinksAreAttachedUpdatedAndRemoved(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Linked');
        $this->login($this->owner);

        $this->post('/task/' . $task->getId() . '/link', ['task_link_form' => ['linkType' => 'url', 'url' => 'https://example.com/spec', 'label' => 'Spec', '_token' => $this->csrf('task_link_form')]]);
        self::assertResponseRedirects(self::BASE . '/task/' . $task->getId());
        $link = $this->em->getRepository(TaskLink::class)->findOneBy(['task' => $task]);
        self::assertInstanceOf(TaskLink::class, $link);

        $this->post('/task/' . $task->getId() . '/link/' . $link->getId(), ['task_link_form' => ['linkType' => 'documentation', 'url' => 'https://example.com/doc', 'label' => 'Doc', '_token' => $this->csrf('task_link_form')]]);
        $this->em->clear();
        self::assertSame(TaskLinkType::Documentation, $this->em->find(TaskLink::class, $link->getId())?->getLinkType());

        $this->post('/task/' . $task->getId() . '/link/' . $link->getId() . '/remove', ['_token' => PredictableCsrfTokenManager::value('task_board_task_link_remove')]);
        $this->em->clear();
        self::assertNull($this->em->find(TaskLink::class, $link->getId()));
        self::assertSame(['task_link', 'task_link_update', 'task_link_remove'], $this->writes());
    }

    public function testMembersAreAddedAndRemoved(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Staffed');
        $this->login($this->owner);

        $this->post('/task/' . $task->getId() . '/member', ['task_member_form' => ['user' => (string) $this->owner->getId(), 'memberRole' => 'assignee', '_token' => $this->csrf('task_member_form')]]);
        $member = $this->em->getRepository(TaskMember::class)->findOneBy(['task' => $task]);
        self::assertInstanceOf(TaskMember::class, $member);
        self::assertSame(TaskMemberRole::Assignee, $member->getMemberRole());

        $memberId = $member->getId();
        $this->post('/task/' . $task->getId() . '/member/' . $memberId . '/remove', ['_token' => PredictableCsrfTokenManager::value('task_board_task_member_remove_' . $memberId)]);
        $this->em->clear();
        self::assertNull($this->em->find(TaskMember::class, $memberId));
        self::assertSame(['task_member', 'task_member_remove'], $this->writes());
    }

    public function testTheAssigneeProviderRefusesOtherUsers(): void
    {
        self::$taskBoardConfig = ['assignee_provider' => 'app.assignee_provider'];
        self::ensureKernelShutdown();
        $this->setUp();
        $other = $this->user('other@example.com');
        $board = $this->board();
        $task  = $this->task($board, 'Owner only');
        $this->login($this->owner);

        $this->post('/task/' . $task->getId() . '/member', ['task_member_form' => ['user' => (string) $this->owner->getId(), 'memberRole' => 'watcher', '_token' => $this->csrf('task_member_form')]]);
        self::assertCount(1, $this->em->getRepository(TaskMember::class)->findBy(['task' => $task]));

        // The choice list only offers the provider's users: a forged id of another user is a refused choice.
        $this->post('/task/' . $task->getId() . '/member', ['task_member_form' => ['user' => (string) $other->getId(), 'memberRole' => 'watcher', '_token' => $this->csrf('task_member_form')]]);
        self::assertCount(1, $this->em->getRepository(TaskMember::class)->findBy(['task' => $task]));

        // A user still listed but no longer assignable is refused by the provider itself (flash, nothing stored).
        OwnerOnlyAssigneeProvider::$offeredEmails = ['other@example.com'];
        try {
            $this->post('/task/' . $task->getId() . '/member', ['task_member_form' => ['user' => (string) $other->getId(), 'memberRole' => 'watcher', '_token' => $this->csrf('task_member_form')]]);
        } finally {
            OwnerOnlyAssigneeProvider::$offeredEmails = [];
        }
        self::assertResponseRedirects(self::BASE . '/task/' . $task->getId());
        self::assertCount(1, $this->em->getRepository(TaskMember::class)->findBy(['task' => $task]));
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testASubtaskIsCreatedUnderItsParent(): void
    {
        $board  = $this->board();
        $parent = $this->task($board, 'Parent');
        $this->login($this->owner);
        $column = $board->getColumns()->first();
        self::assertInstanceOf(BoardColumn::class, $column);

        $this->post('/task/' . $parent->getId() . '/subtask', ['task_form' => ['title' => 'Child', 'columnId' => $column->getId(), '_token' => $this->csrf('task_form')]]);

        $child = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Child']);
        self::assertInstanceOf(Task::class, $child);
        self::assertSame($parent->getId(), $child->getParent()?->getId());
        self::assertSame(['task_subtask'], $this->writes());
    }

    public function testBoardCreationNeedsTheCreateRoleAndAValidForm(): void
    {
        $this->login($this->owner);
        $this->post('/create', ['task_board_form' => ['name' => '', '_token' => $this->csrf('task_board_form')]]);
        self::assertResponseRedirects(self::BASE);

        self::$taskBoardConfig = ['security' => ['create_roles' => ['ROLE_ADMIN']]];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);
        $this->post('/create', ['task_board_form' => ['name' => 'Denied', '_token' => $this->csrf('task_board_form')]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAReadOnlyTaskRefusesTheEditForm(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Frozen');
        $this->login($this->owner);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TaskBoardEvents::TASK_ACCESS_CHECK, static function (TaskAccessCheckEvent $event): void {
            $event->markReadOnly();
        });

        $this->client->request('GET', self::BASE . '/task/' . $task->getId());
        self::assertResponseIsSuccessful();
        $this->post('/task/' . $task->getId(), ['task_form' => ['title' => 'Changed', '_token' => $this->csrf('task_form')]]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAUserWithoutTheAccessRoleIsRefused(): void
    {
        $this->login($this->user('guest@example.com', ['ROLE_GUEST']));
        $this->client->request('GET', self::BASE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDependencyChoicesKeepTasksWithTheSameTitle(): void
    {
        $board = $this->board();
        $task  = $this->task($board, 'Main');
        $this->task($board, 'Twin', position: 1);
        $this->task($board, 'Twin', position: 2);
        $this->login($this->owner);

        $crawler = $this->client->request('GET', self::BASE . '/task/' . $task->getId());

        self::assertSame(['Twin', 'Twin (2)'], $crawler->filter('select[name="task_dependency[taskId]"] option[value!=""]')->each(static fn ($option): string => trim($option->text())));
    }

    public function testTheTenantIndexOpensOrListsTheTenantBoards(): void
    {
        self::$taskBoardConfig = ['tenancy' => ['enabled' => true, 'single_board' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]]];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme');
        self::assertResponseRedirects();
        self::assertStringStartsWith('/t/acme/board/', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', '/t/acme');
        self::assertCount(1, $this->em->getRepository(TaskBoard::class)->findBy(['tenantKey' => 'acme']));

        self::$taskBoardConfig = ['tenancy' => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]]];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->board('Acme roadmap', tenantKey: 'acme');
        $this->board('Other', tenantKey: 'other');
        $this->login($this->owner);

        $this->client->request('GET', '/t/acme');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Acme roadmap');
        self::assertSelectorTextNotContains('body', 'Other');
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $files
     */
    private function post(string $path, array $parameters, array $files = []): void
    {
        $this->client->request('POST', self::BASE . $path, $parameters, $files);
    }

    private function csrf(string $tokenId): string
    {
        return PredictableCsrfTokenManager::value($tokenId);
    }

    private function upload(string $content, string $name = 'tasks.csv'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-import');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function reloadTask(Task $task): Task
    {
        $this->em->clear();
        $reloaded = $this->em->find(Task::class, $task->getId());
        self::assertInstanceOf(Task::class, $reloaded);

        return $reloaded;
    }

    /**
     * @return list<string> actions of the "TaskBoard write" log lines
     */
    private function writes(): array
    {
        $writes = array_filter($this->logger()->records, static fn (array $record): bool => $record['message'] === 'TaskBoard write: {action}');

        return array_values(array_map(static fn (array $record): string => (string) $record['context']['action'], $writes));
    }
}
