<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Question;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\Team;
use Nowo\TaskBoardBundle\Entity\TeamMember;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TeamRole;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskAccessCheckEvent;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionCountsInterface;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\Tests\Functional\App\MemberTenantResolver;
use Nowo\TaskBoardBundle\Tests\Functional\FoldsKeyColumns;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

use function count;
use function is_array;
use function sprintf;

/**
 * Since 2.32.0 (Beacon request, *to answer* menu badge): {@see TaskQuestionView::countToAnswer()} answers
 * `count(mine())` for every viewer — users with and without board access, a manage role, a team, a participant only,
 * tenants, closed questions, questions on archived and deleted tasks, on an archived board and addressed to others —
 * with and without BOARD_ACCESS_CHECK / TASK_ACCESS_CHECK listeners, in a number of queries that does not grow with the
 * number of open questions, and keeps nothing between calls (kept-alive kernel).
 */
final class TaskQuestionCountTest extends QuestionTestCase
{
    use FoldsKeyColumns;

    private AppUser $carol;

    private AppUser $dave;

    private AppUser $admin;

    private AppUser $eve;

    /** @var array<string, Task> */
    private array $tasks = [];

    protected function tearDown(): void
    {
        MemberTenantResolver::$members = [];
        parent::tearDown();
    }

    public function testTheCountIsTheListAcrossTheMatrix(): void
    {
        $this->matrix();

        $this->assertParity();
        self::assertFalse($this->checker()->taskListenersMayDecide(), 'the SQL path');
        self::assertSame(0, $this->countAs($this->eve), 'no board, no task of hers: nothing');
        // admin: every board (security.manage_roles): one open question per live task addressed to them.
        self::assertSame(6, $this->countAs($this->admin));
        // bob: the roadmap task he is a member of (the shared question too), nothing on boards he may not see.
        self::assertSame(2, $this->countAs($this->bob));
        // dave: the team board's task, by the team; carol: her own board's (not the shared one: she may not see that task).
        self::assertSame(1, $this->countAs($this->dave));
        self::assertSame(1, $this->countAs($this->carol));
        // owner: the creator of every task, archived board and archived task included.
        self::assertSame(6, $this->countAs($this->owner));
    }

    public function testTheCountIsTheListWithBoardAndTaskListeners(): void
    {
        $this->matrix();
        $dispatcher = $this->dispatcher();
        $hiddenTask = $this->tasks['roadmap']->getId();
        $shownTask  = $this->tasks['carol']->getId();
        $teamBoard  = $this->tasks['team']->getBoard()->getId();
        $eve        = $this->eve->getId();
        $dispatcher->addListener('nowo_task_board.task_access_check', static function (TaskAccessCheckEvent $event) use ($hiddenTask, $shownTask, $eve): void {
            if ($event->getAction() !== TaskAccessCheckEvent::ACTION_VIEW) {
                return;
            }
            if ($event->getTaskId() === $hiddenTask) {
                $event->deny();
            } elseif ($event->getTaskId() === $shownTask && $event->getSubject() instanceof AppUser && $event->getSubject()->getId() === $eve) {
                $event->grant();
            }
        });
        $dispatcher->addListener('nowo_task_board.board_access_check', static function (BoardAccessCheckEvent $event) use ($teamBoard): void {
            if ($event->getBoardId() === $teamBoard) {
                $event->deny();
            }
        });

        $this->assertParity();
        self::assertTrue($this->checker()->taskListenersMayDecide(), 'the per-task path');
        self::assertSame(1, $this->countAs($this->eve), 'granted one task by the host');
        self::assertSame(0, $this->countAs($this->bob), 'the roadmap task is denied, his participation does not matter');
        self::assertSame(0, $this->countAs($this->dave), 'the team board is denied');
        self::assertSame(4, $this->countAs($this->admin), 'neither the denied task nor the denied board');
        self::assertSame(5, $this->countAs($this->owner), 'a participant still sees a task of a denied board');
    }

    public function testTheCountIsTheListWithABoardListenerOnly(): void
    {
        $this->matrix();
        $roadmap = $this->roadmap->getId();
        $this->dispatcher()->addListener('nowo_task_board.board_access_check', static function (BoardAccessCheckEvent $event) use ($roadmap): void {
            $event->getBoardId() === $roadmap ? $event->deny() : null;
        });

        $this->assertParity();
        self::assertFalse($this->checker()->taskListenersMayDecide());
        // The roadmap board is denied, but bob takes part in its task: canViewTask() still lets him see it.
        self::assertSame(2, $this->countAs($this->bob));
        self::assertSame(4, $this->countAs($this->admin), 'the roadmap and its archived task are hidden');
    }

    public function testTheCountIsTheListUnderTenancy(): void
    {
        self::$taskBoardConfig = [
            'task_questions' => ['enabled' => true, 'recipient_resolver' => 'app.question_recipients'],
            'tenancy'        => ['enabled' => true, 'resolver' => 'app.member_tenant_resolver', 'tenant_paths' => [['path' => '/t/{tenantSlug}', 'name_prefix' => 't_']]],
        ];
        self::ensureKernelShutdown();
        $this->setUp();
        MemberTenantResolver::$members = [
            'acme'   => ['owner@example.com', 'bob@example.com', 'carol@example.com', 'admin@example.com'],
            'globex' => ['carol@example.com', 'admin@example.com'],
        ];
        $this->matrix();

        foreach ([null, 'acme', 'globex', 'nowhere'] as $slug) {
            $this->assertParity($slug);
        }
        self::assertSame(0, $this->countAs($this->admin, 'nowhere'), 'a tenant URL that does not resolve: nothing');
        self::assertSame(0, $this->countAs($this->dave, 'acme'), 'not a member');
        // admin, outside a tenant URL: acme and globex boards only (no untenanted, no `other`).
        self::assertSame(4, $this->countAs($this->admin));
        self::assertSame(3, $this->countAs($this->admin, 'acme'));
        self::assertSame(1, $this->countAs($this->admin, 'globex'));
    }

    /** REQ-SEC-006: on a folding collation a look-alike subject id (`USER:1` for `user:1`) never counts. */
    public function testALookAlikeSubjectNeverCounts(): void
    {
        $this->foldKeyColumns($this->em);
        $owner       = $this->user('owner2@example.com');
        $bob         = $this->user('bob2@example.com');
        $this->owner = $owner;
        $board       = $this->board('Folded', creator: $owner);
        $task        = $this->task($board, 'Folded task');
        $task->addMember(new TaskMember($task, $bob, TaskMemberRole::Assignee));
        $this->em->flush();
        $this->direct($task, [$this->subject($bob)], $owner);
        $this->direct($task, ['USER:' . $bob->getId()], $owner);

        self::assertCount(1, $this->viewAs($bob)->mine());
        self::assertSame(1, $this->countAs($bob));
    }

    /**
     * Audit ZAK-1 (REQ-SEC-006): with `database.user_reference: id` on folding user columns, a task created by `Alice` or
     * with an `ALICE` member row on a board `alice` may not see is not hers: the list hides it, the count does too. Her
     * own task there (creator `alice`) counts in both.
     */
    public function testALookAlikeParticipantNeverCounts(): void
    {
        self::$taskBoardConfig = ['task_questions' => ['enabled' => true], 'database' => ['user_reference' => 'id'], 'user_directory' => 'app.no_user_directory'];
        self::ensureKernelShutdown();
        $this->setUp();
        $this->foldUserColumns();
        $owner = new TestUser('owner', 'owner@example.com');
        $alice = new TestUser('alice', 'alice@example.com');
        $board = new TaskBoard('Hidden', 'hidden', $owner);
        $board->addColumn(new BoardColumn($board, 'To do', 0));
        $this->em->persist($board);
        $created = new Task(board: $board, title: 'Created by Alice', creator: new TestUser('Alice', 'Alice@example.com'), column: $board->getColumns()->get(0));
        $member  = new Task(board: $board, title: 'ALICE is a member', creator: $owner, column: $board->getColumns()->get(0), position: 1);
        $member->addMember(new TaskMember($member, new TestUser('ALICE', 'ALICE@example.com'), TaskMemberRole::Assignee));
        $hers = new Task(board: $board, title: 'Her own', creator: $alice, column: $board->getColumns()->get(0), position: 2);
        foreach ([$created, $member, $hers] as $task) {
            $this->em->persist($task);
            $question = new TaskQuestion($task, 'For alice', null, TaskQuestionChoiceMode::Single, true, $owner, new DateTimeImmutable());
            $question->addRecipient(new TaskQuestionRecipient($question, 'user:alice', 'alice', false, 0));
            $this->em->persist($question);
        }
        $this->em->flush();
        $this->em->clear();

        $this->tokens()->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));
        $view = self::getContainer()->get(TaskQuestionView::class);
        self::assertInstanceOf(TaskQuestionView::class, $view);
        self::assertCount(1, $view->mine());
        self::assertSame(1, $view->countToAnswer());
    }

    public function testNoSubjectOrNoBoardCountsNothingWithoutAQuery(): void
    {
        // A viewer without an id has no subject id: nothing to count.
        self::assertSame(0, $this->queries(fn (): int => $this->countAs(new AppUser('unsaved@example.com'))));
        $query = $this->query();
        self::assertSame([], $query->boardsOpenForSubjects([]));
        self::assertSame([], $query->tasksOpenForSubjects(['user:1'], []));
        self::assertSame(0, $query->countOpenForSubjects(['user:1'], []));
        self::assertSame(0, $query->countOpenOnTasks([], ['task']));
    }

    /**
     * The badge's budget: N = 5 and N = 300 open questions (each on its own task) cost the same queries, on the SQL path
     * and with a TASK_ACCESS_CHECK listener.
     */
    public function testTheQueriesDoNotGrowWithTheQuestions(): void
    {
        $board = $this->board('Busy');
        $few   = $this->openQuestions($board, 5);
        $quiet = $this->queries(fn (): int => $this->assertCountAs($few, $this->bob));

        $this->openQuestions($board, 295);
        self::assertSame($quiet, $this->queries(fn (): int => $this->assertCountAs(300, $this->bob)), 'SQL path');
        self::assertLessThanOrEqual(4, $quiet);

        $this->dispatcher()->addListener('nowo_task_board.task_access_check', static function (): void {
        });
        $listened = $this->queries(fn (): int => $this->assertCountAs(300, $this->bob));
        // The 300 questions closed: their tasks stay, with no open question.
        $this->em->createQuery('UPDATE ' . TaskQuestion::class . ' q SET q.state = :closed')->setParameter('closed', TaskQuestionState::Withdrawn->value)->execute();
        $this->openQuestions($board, 5, offset: 1000);
        self::assertSame($listened, $this->queries(fn (): int => $this->assertCountAs(5, $this->bob)), 'per-task path');
        self::assertLessThanOrEqual(5, $listened);
    }

    /**
     * REQ-RUNTIME-002: one kept-alive kernel, no reset, users alternating: each call reads the database again, nothing of
     * one viewer is seen by the next, and the Twig function and the interface answer the same.
     */
    public function testNothingIsKeptBetweenCalls(): void
    {
        $this->matrix();
        $counts = self::getContainer()->get(TaskQuestionCountsInterface::class);
        self::assertInstanceOf(TaskQuestionView::class, $counts);
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $template = $twig->createTemplate('{{ nowo_task_board_my_questions_count() }}');

        for ($round = 0; $round < 3; ++$round) {
            foreach ([[$this->admin, 6 + $round], [$this->eve, 0], [$this->bob, 2]] as [$user, $expected]) {
                $this->viewAs($user);
                self::assertSame($expected, $counts->countToAnswer(), sprintf('%s, round %d', $user->getUserIdentifier(), $round));
                self::assertSame((string) $expected, $template->render());
            }
            // A new question for admin between rounds: the next call sees it.
            $this->direct($this->tasks['untenanted'], [$this->subject($this->admin)], $this->owner);
        }
        $this->tokens()->setToken(null);
        self::assertSame(0, $counts->countToAnswer(), 'no viewer');
        self::assertSame('0', $template->render());
    }

    /**
     * Boards: roadmap (acme, the owner's; its task has bob as a member), carol's (acme), a team board (globex, dave's
     * team), an untenanted board, a board of a tenant `other` and an archived board. Tasks: one per board, plus an
     * archived one and a deleted one on the roadmap. Every task has one open question for each user, plus an answered
     * and a withdrawn one; the roadmap task also has a question for bob and carol together and one for someone else.
     */
    private function matrix(): void
    {
        $this->carol = $this->user('carol@example.com');
        $this->dave  = $this->user('dave@example.com');
        $this->admin = $this->user('admin@example.com', ['ROLE_ADMIN']);
        $this->eve   = $this->user('eve@example.com');
        $team        = new Team('Eng');
        $this->em->persist($team);
        $this->em->persist(new TeamMember($team, $this->dave, TeamRole::Member));
        $teamBoard = $this->board('Team', tenantKey: 'globex');
        $teamBoard->setTeam($team);
        $archivedBoard = $this->board('Old', tenantKey: 'other');
        $archivedBoard->archive();
        $this->em->flush();

        $this->tasks = [
            'roadmap'    => $this->task,
            'carol'      => $this->task($this->board('Carol', creator: $this->carol, tenantKey: 'acme'), 'Carol task'),
            'team'       => $this->task($teamBoard, 'Team task'),
            'untenanted' => $this->task($this->board('Untenanted'), 'Untenanted task'),
            'old'        => $this->task($archivedBoard, 'Old task'),
            'archived'   => $this->task($this->roadmap, 'Archived task'),
        ];
        $this->tasks['archived']->archive();
        $deleted = $this->task($this->roadmap, 'Deleted task');
        $this->em->flush();

        $everyone = [$this->owner, $this->bob, $this->carol, $this->dave, $this->admin, $this->eve];
        foreach ([...$this->tasks, $deleted] as $task) {
            foreach ($everyone as $user) {
                $this->direct($task, [$this->subject($user)], $this->owner);
                $this->direct($task, [$this->subject($user)], $this->owner, TaskQuestionState::Answered);
                $this->direct($task, [$this->subject($user)], $this->owner, TaskQuestionState::Withdrawn);
            }
        }
        $this->direct($this->task, [$this->subject($this->bob), $this->subject($this->carol)], $this->owner);
        $this->direct($this->task, ['user:nobody', 'contact:client'], $this->owner);
        $this->em->remove($deleted);
        $this->em->flush();
        $this->em->clear();
    }

    /** The user reference columns of tasks and task members on a folding collation (SQLite NOCASE; MySQL `_general_ci`). */
    private function foldUserColumns(): void
    {
        $platform  = $this->em->getConnection()->getDatabasePlatform();
        $collation = match (true) {
            $platform instanceof SQLitePlatform        => 'NOCASE',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_general_ci',
            default                                    => null,
        };
        if ($collation === null) {
            self::markTestSkipped('PostgreSQL has no built-in case-insensitive collation; its columns compare exactly.');
        }
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ([Task::class => 'creator', TaskMember::class => 'user'] as $class => $field) {
            $this->em->getClassMetadata($class)->fieldMappings[$field]->options = ['collation' => $collation];
        }
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($metadata);
        $this->em->clear();
    }

    private function assertParity(?string $tenantSlug = null): void
    {
        foreach ([$this->owner, $this->bob, $this->carol, $this->dave, $this->admin, $this->eve] as $user) {
            $listed = $this->atRequest($tenantSlug, fn (): int => count($this->viewAs($user)->mine()));
            self::assertSame($listed, $this->countAs($user, $tenantSlug), sprintf('%s at %s', $user->getUserIdentifier(), $tenantSlug ?? '(no tenant)'));
        }
    }

    private function countAs(AppUser $user, ?string $tenantSlug = null): int
    {
        return $this->atRequest($tenantSlug, fn (): int => $this->viewAs($user)->countToAnswer());
    }

    private function assertCountAs(int $expected, AppUser $user): int
    {
        $count = $this->countAs($user);
        self::assertSame($expected, $count);

        return $count;
    }

    /**
     * `$n` tasks on `$board`, bob a member of each, each with one open question for bob (one flush).
     */
    private function openQuestions(TaskBoard $board, int $n, int $offset = 0): int
    {
        $bob = $this->em->find(AppUser::class, $this->bob->getId());
        self::assertInstanceOf(AppUser::class, $bob);
        $board = $this->em->find(TaskBoard::class, $board->getId());
        self::assertInstanceOf(TaskBoard::class, $board);
        for ($i = 0; $i < $n; ++$i) {
            $task = new Task(board: $board, title: 'Task ' . ($offset + $i), creator: $board->getCreator(), column: $board->getColumns()->get(0), position: $offset + $i);
            $task->addMember(new TaskMember($task, $bob, TaskMemberRole::Assignee));
            $this->em->persist($task);
            $question = new TaskQuestion($task, 'Question ' . $i, null, TaskQuestionChoiceMode::Single, true, $board->getCreator() ?? $bob, new DateTimeImmutable());
            $question->addRecipient(new TaskQuestionRecipient($question, $this->subject($bob), 'Bob', false, 0));
            $this->em->persist($question);
        }
        $this->em->flush();
        $this->em->clear();

        return $n;
    }

    /**
     * A question on `$task` for `$subjects`, written as rows (the asker's right is not what these tests are about).
     *
     * @param list<string> $subjects
     */
    private function direct(Task $task, array $subjects, AppUser $asker, TaskQuestionState $state = TaskQuestionState::Open): void
    {
        $task     = $this->em->find(Task::class, $task->getId()) ?? $task;
        $asker    = $this->em->find(AppUser::class, $asker->getId()) ?? $asker;
        $question = new TaskQuestion($task, 'Direct question', null, TaskQuestionChoiceMode::Single, true, $asker, new DateTimeImmutable());
        foreach ($subjects as $position => $subject) {
            $question->addRecipient(new TaskQuestionRecipient($question, $subject, $subject, false, $position));
        }
        $this->em->persist($question);
        $this->em->flush();
        if ($state !== TaskQuestionState::Open) {
            $this->em->createQuery('UPDATE ' . TaskQuestion::class . ' q SET q.state = :state WHERE q.id = :id')
                ->setParameter('state', $state->value)->setParameter('id', $question->getId())->execute();
        }
    }

    /** The queries `$run` makes, every connection included. */
    private function queries(callable $run): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $this->em->clear();
        $holder->reset();
        $run();
        $count = 0;
        foreach ($holder->getData() as $queries) {
            $count += is_array($queries) ? count($queries) : 0;
        }

        return $count;
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function atRequest(?string $tenantSlug, callable $call): mixed
    {
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $request = Request::create('/host/dashboard');
        $request->setSession(new Session(new MockArraySessionStorage()));
        if ($tenantSlug !== null) {
            $request->attributes->set('tenantSlug', $tenantSlug);
        }
        $stack->push($request);
        try {
            return $call();
        } finally {
            $stack->pop();
        }
    }

    private function viewAs(AppUser $user): TaskQuestionView
    {
        $this->tokens()->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $view = self::getContainer()->get(TaskQuestionView::class);
        self::assertInstanceOf(TaskQuestionView::class, $view);

        return $view;
    }

    private function tokens(): TokenStorageInterface
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);

        return $tokens;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function checker(): TaskBoardResourceAccessChecker
    {
        $checker = self::getContainer()->get(TaskBoardResourceAccessChecker::class);
        self::assertInstanceOf(TaskBoardResourceAccessChecker::class, $checker);

        return $checker;
    }
}
