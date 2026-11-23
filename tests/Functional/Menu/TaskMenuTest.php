<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Menu;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Menu\TaskMenuOperation;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_values;
use function sort;

/**
 * Since 2.22.0 (owner request via Beacon): the menus of the board cards, list rows and column headers, and their writes.
 *
 * The permission matrix: each role sees exactly the entries it may use (manager, editor, reader), and every write entry,
 * posted directly by someone the menu does not offer it to, is refused on the server (403), as are a wrong CSRF token, a
 * cross-origin request and an unknown action.
 */
final class TaskMenuTest extends FunctionalTestCase
{
    private const MENU = '/tools/task-board/menu/task';

    private TaskBoard $board;

    private Task $task;

    private AppUser $editor;

    private AppUser $reader;

    protected function setUp(): void
    {
        self::$taskBoardConfig = self::$taskBoardConfig !== [] ? self::$taskBoardConfig : ['move' => ['enabled' => true]];
        parent::setUp();
        $this->editor = $this->user('editor@example.com');
        $this->reader = $this->user('reader@example.com');
        $this->board  = $this->board();
        $this->task   = $this->task($this->board, 'Write the release notes');
        $editorId     = $this->editor->getId();
        $readerId     = $this->reader->getId();
        // The host's roles: the editor edits the board but does not manage it; the reader only reads it.
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($editorId, $readerId): void {
            $subject = $event->getSubject();
            $id      = $subject instanceof AppUser ? $subject->getId() : null;
            if ($id !== $editorId && $id !== $readerId) {
                return;
            }
            if ($event->getAction() === BoardAccessCheckEvent::ACTION_MANAGE) {
                $event->deny();

                return;
            }
            $event->grant();
            if ($id === $readerId) {
                $event->markReadOnly();
            }
        });
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function roles(): iterable
    {
        yield 'board manager' => ['owner', ['archive', 'assign', 'copy-link', 'delete', 'duplicate', 'edit', 'move', 'open', 'tags'], ['add-task', 'collapse', 'rename']];
        yield 'editor' => ['editor', ['archive', 'assign', 'copy-link', 'duplicate', 'edit', 'move', 'open', 'tags'], ['add-task', 'collapse', 'rename']];
        yield 'reader' => ['reader', ['copy-link', 'open'], ['collapse']];
    }

    /**
     * @param list<string> $cardEntries
     * @param list<string> $columnEntries
     */
    #[DataProvider('roles')]
    public function testEachRoleSeesOnlyTheEntriesItMayUse(string $role, array $cardEntries, array $columnEntries): void
    {
        $this->login($this->as($role));
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId() . '?view=board');
        self::assertResponseIsSuccessful();

        $card = $crawler->filter('[data-task-id="' . $this->task->getId() . '"]');
        self::assertSame('0', $card->attr('tabindex'), 'the card is focusable (Shift+F10, ContextMenu key)');
        self::assertNotNull($card->attr('data-nowo-ui-context-menu-scope'));
        self::assertSame($cardEntries, $this->entries($card->filter('[data-testid="nowo-task-menu-panel"]')));
        $header = $crawler->filter('.nowo-task-column__header')->first();
        self::assertSame($columnEntries, $this->entries($header->filter('[data-testid="nowo-task-column-menu-panel"]')));
        // The kebab's toggle is a <summary>: reachable with Tab on every card and header.
        self::assertCount(1, $card->filter('[data-testid="nowo-task-menu"] > summary'));
        // One CSRF-only form for the whole page (REQ-TWIG-005); the write entries are its submit buttons.
        self::assertCount(1, $crawler->filter('form#nowo-task-menu-form'));
        $writes = $card->filter('button[form="nowo-task-menu-form"]');
        self::assertSame($role === 'reader' ? 0 : ($role === 'owner' ? 3 : 2), $writes->count());
        // Status, Priority and Due date: a slot the page's shared template fills (task-board.js), only for those who may write.
        self::assertCount($role === 'reader' ? 0 : 1, $card->filter('[data-nowo-task-menu-shared="' . $this->task->getId() . '"]'));
        self::assertCount(1, $crawler->filter('template#nowo-task-menu-shared'));
        // Off by default: UiKit's theme.context_menus is off and web_ui.context_menus follows it.
        self::assertCount(0, $crawler->filter('[data-nowo-ui-context-menu-source]'));
    }

    public function testTheSharedTemplateHoldsEveryColumnPriorityAndDueDate(): void
    {
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId() . '?view=board');
        $card    = $crawler->filter('[data-task-id="' . $this->task->getId() . '"]');
        $slot    = $card->filter('[data-nowo-task-menu-shared]');
        self::assertSame([$this->columnId('To do'), 'normal'], [$slot->attr('data-column'), $slot->attr('data-priority')]);

        $template = $crawler->filter('template#nowo-task-menu-shared')->html();
        foreach (['To do', 'Doing', 'Done'] as $column) {
            self::assertStringContainsString('value="__TASK__|status:' . $this->columnId($column) . '"', $template);
        }
        foreach (['low', 'normal', 'high', 'urgent'] as $priority) {
            self::assertStringContainsString('value="__TASK__|priority:' . $priority . '"', $template);
        }
        foreach (['today', 'tomorrow', 'next_week', 'none'] as $choice) {
            self::assertStringContainsString('value="__TASK__|due:' . $choice . '"', $template);
        }
        self::assertStringContainsString('/tools/task-board/task/' . $this->task->getId(), (string) $card->filter('[data-nowo-task-menu="copy-link"]')->attr('data-nowo-ui-clipboard-text'));
        self::assertNotSame('', (string) $card->filter('[data-nowo-task-menu="delete"]')->attr('data-confirm'));
    }

    public function testTheListRowsCarryTheSameMenu(): void
    {
        $this->login($this->editor);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId() . '/list');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('tr[data-task-id="' . $this->task->getId() . '"]');
        self::assertSame('0', $row->attr('tabindex'));
        self::assertSame(['archive', 'assign', 'copy-link', 'duplicate', 'edit', 'move', 'open', 'tags'], $this->entries($row->filter('[data-testid="nowo-task-menu-panel"]')));
        self::assertCount(1, $row->filter('[data-nowo-task-menu-shared]'));
        self::assertCount(1, $crawler->filter('template#nowo-task-menu-shared'));
        self::assertStringContainsString('from=list', (string) $row->filter('[data-nowo-task-menu="open"]')->attr('href'));
        self::assertCount(1, $crawler->filter('form#nowo-task-menu-form'));
        self::assertStringContainsString('_target_path=', (string) $crawler->filter('form#nowo-task-menu-form')->attr('action'));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function writes(): iterable
    {
        yield 'status' => ['status', 'Doing'];
        yield 'priority' => ['priority', 'high'];
        yield 'due date' => ['due', 'tomorrow'];
        yield 'assign to me' => ['assign_me', null];
        yield 'archive' => ['archive', null];
        yield 'delete' => ['delete', null];
    }

    #[DataProvider('writes')]
    public function testTheManagerWritesThroughTheMenu(string $operation, ?string $value): void
    {
        $this->login($this->owner);
        $taskId = $this->task->getId();
        $this->post($operation, $operation === 'status' ? $this->columnId((string) $value) : $value, '/tools/task-board/board/' . $this->board->getId() . '/list');

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/tools/task-board/board/' . $this->board->getId() . '/list', $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $task = $this->em->find(Task::class, $taskId);
        match ($operation) {
            'status'    => self::assertSame('Doing', $task?->getColumn()?->getName()),
            'priority'  => self::assertSame(TaskPriority::High, $task?->getPriority()),
            'due'       => self::assertNotNull($task?->getDueAt()),
            'assign_me' => self::assertCount(1, $task?->getMembers() ?? []),
            'archive'   => self::assertTrue($task?->isArchived()),
            default     => self::assertNull($task),
        };
        self::assertTrue($this->logged('task_menu_' . $operation));
    }

    public function testRemoveMeAndNoDueDate(): void
    {
        $this->task->addMember(new TaskMember(task: $this->task, user: $this->owner, memberRole: TaskMemberRole::Assignee));
        $this->task->addMember(new TaskMember(task: $this->task, user: $this->owner, memberRole: TaskMemberRole::Watcher));
        $this->task->setDueAt(new DateTimeImmutable('2026-12-01'));
        $this->em->flush();
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId() . '?view=board');
        self::assertCount(1, $crawler->filter('button[value="' . $this->task->getId() . '|unassign_me"]'));
        self::assertCount(0, $crawler->filter('button[value="' . $this->task->getId() . '|assign_me"]'));

        $this->post('unassign_me');
        $this->post('due', 'none');
        $this->em->clear();
        $task = $this->em->find(Task::class, $this->task->getId());
        self::assertCount(0, $task?->getMembers() ?? [1]);
        self::assertNull($task?->getDueAt());
        // Nothing left to remove: no write, back to the board.
        $this->post('unassign_me');
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/tools/task-board/board/' . $this->board->getId(), $this->client->getResponse()->headers->get('Location'));
    }

    public function testADeletedTaskGoesBackToItsBoardRatherThanItsGonePage(): void
    {
        $this->login($this->owner);
        $this->post('delete', null, '/tools/task-board/task/' . $this->task->getId());

        self::assertSame('/tools/task-board/board/' . $this->board->getId(), $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Every entry the menu hides from a role, posted directly anyway: 403, nothing written.
     *
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function hiddenWrites(): iterable
    {
        foreach (['status', 'priority', 'due', 'assign_me', 'unassign_me', 'archive', 'delete', 'timer'] as $operation) {
            yield 'reader: ' . $operation => ['reader', $operation, null];
        }
        yield 'editor: delete' => ['editor', 'delete', null];
        yield 'editor: timer (no TimeTrack)' => ['editor', 'timer', null];
        yield 'manager: timer (no TimeTrack)' => ['owner', 'timer', null];
    }

    #[DataProvider('hiddenWrites')]
    public function testADirectCallToAHiddenEntryIsRefused(string $role, string $operation, ?string $value): void
    {
        $this->login($this->as($role));
        $value ??= match ($operation) {
            'status'   => $this->columnId('Doing'),
            'priority' => 'urgent',
            'due'      => 'today',
            default    => null,
        };
        $this->post($operation, $value);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $task = $this->em->find(Task::class, $this->task->getId());
        self::assertSame(['To do', TaskPriority::Normal, null, false, 0], [$task?->getColumn()?->getName(), $task?->getPriority(), $task?->getDueAt(), $task?->isArchived(), $task?->getMembers()->count()]);
    }

    public function testAnArchivedTaskTakesNoMoreMenuWrites(): void
    {
        $this->task->archive();
        $this->em->flush();
        $this->login($this->owner);

        $this->post('priority', 'high');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, int}>
     */
    public static function refusedRequests(): iterable
    {
        yield 'wrong CSRF token' => [['_token' => 'csrf-other'], [], 403];
        yield 'no CSRF token' => [['_token' => ''], [], 403];
        yield 'cross-site fetch' => [[], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], 403];
        yield 'same-site fetch' => [[], ['HTTP_SEC_FETCH_SITE' => 'same-site'], 403];
        yield 'another origin' => [[], ['HTTP_ORIGIN' => 'https://evil.example'], 403];
        yield 'another port' => [[], ['HTTP_ORIGIN' => 'http://localhost:8080'], 403];
        yield 'unknown operation' => [['action' => '{task}|drop'], [], 400];
        yield 'no task' => [['action' => '|archive'], [], 400];
        yield 'unknown priority' => [['action' => '{task}|priority:critical'], [], 400];
        yield 'unknown due date' => [['action' => '{task}|due:yesterday'], [], 400];
        yield 'a task that does not exist' => [['action' => 'missing|archive'], [], 404];
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $server
     */
    #[DataProvider('refusedRequests')]
    public function testARefusedRequestWritesNothing(array $body, array $server, int $status): void
    {
        $this->login($this->owner);
        $action = isset($body['action']) ? str_replace('{task}', $this->task->getId(), $body['action']) : $this->task->getId() . '|archive';
        $this->client->request('POST', self::MENU, ['action' => $action] + $body + ['_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID)], [], $server);

        self::assertResponseStatusCodeSame($status);
        $this->em->clear();
        self::assertFalse($this->em->find(Task::class, $this->task->getId())?->isArchived());
    }

    public function testSameOriginRequestsPass(): void
    {
        $this->login($this->owner);
        $this->client->request('POST', self::MENU, ['action' => $this->task->getId() . '|priority:low', '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID)], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(303);
        $this->client->request('POST', self::MENU, ['action' => $this->task->getId() . '|priority:high', '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID)], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(303);
        // A target path that is not local is ignored (no open redirect).
        $this->client->request('POST', self::MENU . '?_target_path=//evil.example', ['action' => $this->task->getId() . '|priority:low', '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID)]);
        self::assertSame('/tools/task-board/board/' . $this->board->getId(), $this->client->getResponse()->headers->get('Location'));
    }

    public function testAStatusTheBoardDoesNotHaveIsRefusedWithAFlash(): void
    {
        $this->login($this->owner);
        $this->post('status', 'not-a-column');

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertSame('To do', $this->em->find(Task::class, $this->task->getId())?->getColumn()?->getName());
    }

    public function testADueDateBeforeTheStartIsRefusedWithAFlash(): void
    {
        $this->task->setStartAt(new DateTimeImmutable('+30 days'));
        $this->em->flush();
        $this->login($this->owner);
        $this->post('due', 'today');

        self::assertResponseStatusCodeSame(303);
        $this->em->clear();
        self::assertNull($this->em->find(Task::class, $this->task->getId())?->getDueAt());
    }

    public function testArchivingTwiceWritesOnce(): void
    {
        $this->login($this->owner);
        $this->post('archive');
        // The task is archived: the menu offers no write any more, and the route refuses it.
        $this->post('archive');
        self::assertResponseStatusCodeSame(403);
    }

    private function as(string $role): AppUser
    {
        return match ($role) {
            'editor' => $this->editor,
            'reader' => $this->reader,
            default  => $this->owner,
        };
    }

    private function post(string $operation, ?string $value = null, ?string $target = null): void
    {
        $this->client->request('POST', self::MENU . ($target !== null ? '?_target_path=' . rawurlencode($target) : ''), [
            'action' => TaskMenuOperation::value($this->task->getId(), $operation, $value),
            '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID),
        ]);
    }

    private function columnId(string $name): string
    {
        foreach ($this->board->getColumns() as $column) {
            if ($column->getName() === $name) {
                return $column->getId();
            }
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private function entries(Crawler $panel): array
    {
        $entries = array_values(array_unique($panel->filter('[data-nowo-task-menu]')->each(static fn ($node): string => (string) $node->attr('data-nowo-task-menu'))));
        sort($entries);

        return $entries;
    }

    private function logged(string $action): bool
    {
        foreach ($this->logger()->records as $record) {
            if (($record['context']['action'] ?? null) === $action) {
                return true;
            }
        }

        return false;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
