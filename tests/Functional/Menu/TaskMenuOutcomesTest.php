<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Menu;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Controller\TaskMenuController;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Menu\TaskMenuOperation;
use Nowo\TaskBoardBundle\Menu\TaskTimerStart;
use Nowo\TaskBoardBundle\Sync\Hook\LimitRefusal;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\PredictableCsrfTokenManager;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTimerStarter;
use Nowo\TaskBoardBundle\Tests\Functional\App\ScriptedTransitionPolicy;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Since 2.22.0: what a menu write answers when the service behind it refuses — the transition policy, a task changed
 * meanwhile, the assignee policy, the time tracker — and the "Start timer" entry with a time tracker. Each refusal is a
 * flash on the page it came from; nothing is written.
 */
final class TaskMenuOutcomesTest extends FunctionalTestCase
{
    private TaskBoard $board;

    private Task $task;

    protected function tearDown(): void
    {
        ScriptedTransitionPolicy::$meanwhile = null;
        ScriptedTransitionPolicy::$asked     = [];
        ScriptedTimerStarter::reset();
        parent::tearDown();
    }

    public function testAStatusThePolicyRefusesShowsItsMessage(): void
    {
        $this->boot(['column_transition_policy' => 'app.transition_policy']);
        $this->login($this->owner);
        $this->post('status', $this->columnId('Blocked'));

        self::assertResponseStatusCodeSame(303);
        $this->assertFlash('A blocked task needs a reason');
        $this->assertColumn('To do');
    }

    public function testAStatusOfATaskChangedMeanwhileWritesNothing(): void
    {
        $this->boot(['column_transition_policy' => 'app.transition_policy']);
        $connection                          = $this->em->getConnection();
        $taskId                              = $this->task->getId();
        $done                                = $this->columnId('Done');
        ScriptedTransitionPolicy::$meanwhile = static function () use ($connection, $taskId, $done): void {
            $connection->executeStatement('UPDATE task_board_tasks SET column_id = ? WHERE id = ?', [$done, $taskId]);
        };
        $this->login($this->owner);
        $this->post('status', $this->columnId('Doing'));

        self::assertResponseStatusCodeSame(303);
        $this->assertFlash('task_board.flash.task_changed_meanwhile');
        $this->assertColumn('Done');
    }

    public function testTheFlashOfEachMoveRefusal(): void
    {
        self::assertSame('task_board.flash.task_reopen_refused', TaskMenuController::moveRefusal(new TaskReopenRefusedException(new LimitRefusal('limit'))));
        self::assertSame('task_board.flash.task_changed_meanwhile', TaskMenuController::moveRefusal(TaskTreeRefusedException::conflict()));
    }

    public function testInAWeekAndAnUnassignableUser(): void
    {
        $this->boot(['assignee_provider' => 'app.assignee_provider']);
        $editor   = $this->user('editor@example.com');
        $editorId = $editor->getId();
        $this->dispatcher()->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event) use ($editorId): void {
            $subject = $event->getSubject();
            if ($subject instanceof AppUser && $subject->getId() === $editorId && $event->getAction() !== BoardAccessCheckEvent::ACTION_MANAGE) {
                $event->grant();
            }
        });
        $this->login($editor);

        $this->post('due', 'next_week');
        $this->em->clear();
        self::assertSame((new DateTimeImmutable('+7 days'))->format('Y-m-d'), $this->em->find(Task::class, $this->task->getId())?->getDueAt()?->format('Y-m-d'));

        // The host's assignee policy only lets the board's creator be assigned.
        $this->post('assign_me');
        self::assertResponseStatusCodeSame(303);
        $this->assertFlash('task_board.flash.member_not_assignable');
        $this->em->clear();
        self::assertCount(0, $this->em->find(Task::class, $this->task->getId())?->getMembers() ?? [1]);
    }

    /**
     * @return iterable<string, array{TaskTimerStart, string}>
     */
    public static function timers(): iterable
    {
        yield 'started' => [TaskTimerStart::Started, 'task_board.menu.flash.timer_started'];
        yield 'already running' => [TaskTimerStart::AlreadyRunning, 'task_board.menu.flash.timer_already_running'];
        yield 'refused' => [TaskTimerStart::Refused, 'task_board.menu.flash.timer_refused'];
    }

    #[DataProvider('timers')]
    public function testStartTimerWithATimeTracker(TaskTimerStart $outcome, string $flash): void
    {
        $this->boot(['__timer' => true]);
        ScriptedTimerStarter::$outcome = $outcome;
        $this->login($this->owner);
        $crawler = $this->client->request('GET', '/tools/task-board/board/' . $this->board->getId() . '?view=board');
        self::assertCount(1, $crawler->filter('button[value="' . $this->task->getId() . '|timer"]'), 'offered with a time tracker');

        $this->post('timer');

        self::assertResponseStatusCodeSame(303);
        $this->assertFlash($flash);
        self::assertSame($outcome === TaskTimerStart::Started ? [$this->task->getId()] : [], ScriptedTimerStarter::$started);
    }

    /** TB-L1: a dot-segment `_target_path` falls back to the board, never a 500. */
    public function testADotSegmentTargetPathFallsBackToTheBoard(): void
    {
        $this->boot([]);
        $this->login($this->owner);
        foreach (['/tools/task-board/../x', '/tools/task-board/%2e%2e/x'] as $bad) {
            $this->client->request('POST', '/tools/task-board/menu/task?_target_path=' . $bad, [
                'action' => TaskMenuOperation::value($this->task->getId(), 'due', 'next_week'),
                '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID),
            ]);
            self::assertResponseRedirects('/tools/task-board/board/' . $this->board->getId(), 303, $bad);
        }
    }

    public function testAnAnonymousRequestIsRefused(): void
    {
        $this->boot([]);
        $this->post('archive');

        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
        $this->em->clear();
        self::assertFalse($this->em->find(Task::class, $this->task->getId())?->isArchived());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config): void
    {
        self::ensureKernelShutdown();
        self::$taskBoardConfig = $config;
        $this->setUp();
        $this->board = $this->board('Roadmap', ['To do', 'Doing', 'Done', 'Blocked']);
        $this->task  = $this->task($this->board, 'Ship it');
    }

    private function post(string $operation, ?string $value = null): void
    {
        $this->client->request('POST', '/tools/task-board/menu/task?_target_path=' . rawurlencode('/tools/task-board/board/' . $this->board->getId() . '/list'), [
            'action' => TaskMenuOperation::value($this->task->getId(), $operation, $value),
            '_token' => PredictableCsrfTokenManager::value(TaskMenuOperation::CSRF_TOKEN_ID),
        ]);
    }

    private function assertFlash(string $text): void
    {
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString($text, $crawler->text());
    }

    private function assertColumn(string $name): void
    {
        $this->em->clear();
        self::assertSame($name, $this->em->find(Task::class, $this->task->getId())?->getColumn()?->getName());
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

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
