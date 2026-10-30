<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Comment\MentionCandidate;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskCommentFormData;
use Nowo\TaskBoardBundle\Dto\TaskDependencyFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskTimeEntryFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetApplyFormType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskDependencyFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Gantt\ConfiguredWeekStartResolver;
use Nowo\TaskBoardBundle\Import\TaskBoard\TaskBoardCsvImporter;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\ColumnSetRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function count;
use function in_array;

/**
 * IDOR regression: a ROLE_USER must not read or mutate boards/tasks they neither own nor share a team with.
 */
final class TaskBoardManageControllerAccessTest extends TestCase
{
    private TestUser $owner;

    private TestUser $attacker;

    private TaskBoard $board;

    private BoardColumn $column;

    private Task $task;

    /** @var array<string, mixed> */
    private array $rendered = [];

    private string $renderedTemplate = '';

    /** @var list<array{type: string, data: mixed, options: array<string, mixed>}> */
    private array $createdForms = [];

    /** @var list<bool> */
    private array $findByBoardIncludeCompleted = [];

    /** @var list<Task> Since 1.15: more tasks the repository knows (dependency targets). */
    private array $extraTasks = [];

    /** @var array<string, TaskComment> Since 1.16: the comments the comment repository holds. */
    private array $comments = [];

    private ?TaskCommentRepositoryInterface $commentRepositoryMock = null;

    protected function setUp(): void
    {
        $this->owner    = new TestUser('1', 'owner@example.com');
        $this->attacker = new TestUser('2', 'attacker@example.com');
        $this->board    = new TaskBoard('Private', 'private', $this->owner);
        $this->column   = new BoardColumn($this->board, 'Todo', 0);
        $this->board->addColumn($this->column);
        $this->task = new Task($this->board, 'Secret', $this->owner, column: $this->column);
    }

    /** @return iterable<string, array{string, list<string>, array<string, mixed>}> */
    public static function foreignBoardActions(): iterable
    {
        yield 'board' => ['board', ['board'], []];
        yield 'list' => ['listView', ['board'], []];
        yield 'gantt' => ['ganttView', ['board'], []];
        yield 'create column' => ['createColumn', ['board', 'request'], []];
        yield 'update column' => ['updateColumn', ['board', 'column', 'request'], []];
        yield 'reorder columns' => ['reorderColumns', ['board', 'request'], []];
        yield 'create task' => ['createTask', ['board', 'request'], []];
        yield 'import' => ['importBoard', ['board', 'request'], []];
        yield 'import into task' => ['importIntoTask', ['task', 'request'], []];
        yield 'task detail' => ['task', ['task', 'request'], []];
        yield 'advance task' => ['advanceTask', ['task', 'request'], ['action' => 'done']];
        yield 'move task' => ['moveTask', ['task', 'request'], []];
        yield 'attach link' => ['attachLink', ['task', 'request'], []];
        yield 'update link' => ['updateLink', ['task', 'link', 'request'], []];
        yield 'remove link' => ['removeLink', ['task', 'link', 'request'], []];
        yield 'add member' => ['addMember', ['task', 'request'], []];
        yield 'remove member' => ['removeMember', ['task', 'member', 'request'], []];
        yield 'priority' => ['updatePriority', ['task', 'request'], ['priority' => 'high']];
        yield 'subtask' => ['createSubtask', ['task', 'request'], []];
        yield 'delete column' => ['deleteColumn', ['board', 'column', 'request'], []];
        yield 'save column set' => ['createColumnSet', ['board', 'request'], []];
        yield 'apply column set' => ['applyColumnSet', ['board', 'request'], []];
        yield 'delete column set' => ['deleteColumnSet', ['board', 'set', 'request'], []];
        yield 'task panel' => ['taskPanel', ['task', 'request'], []];
        yield 'add dependency' => ['addDependency', ['task', 'request'], []];
        yield 'remove dependency' => ['removeDependency', ['task', 'dependency', 'request'], []];
        yield 'log time' => ['logTime', ['task', 'request'], []];
        yield 'remove time entry' => ['removeTimeEntry', ['task', 'entry', 'request'], []];
        yield 'comment' => ['addComment', ['task', 'request'], []];
        yield 'edit comment' => ['editComment', ['task', 'comment', 'request'], []];
        yield 'delete comment' => ['deleteComment', ['task', 'comment', 'request'], []];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, mixed> $post
     */
    #[DataProvider('foreignBoardActions')]
    public function testForeignBoardAndTaskAreForbidden(string $action, array $arguments, array $post): void
    {
        $controller = $this->controller($this->attacker);
        $request    = Request::create('/', 'POST', $post + ['_token' => 'ok']);
        $args       = array_map(fn (string $argument): mixed => match ($argument) {
            'board'   => $this->board->getId(),
            'task'    => $this->task->getId(),
            'column'  => $this->column->getId(),
            'request' => $request,
            default   => 'some-id',
        }, $arguments);

        $this->expectException(AccessDeniedException::class);
        $controller->{$action}(...$args);
    }

    /** @return iterable<string, array{string, list<string>, array<string, mixed>}> */
    public static function writeActions(): iterable
    {
        foreach (self::foreignBoardActions() as $name => $case) {
            // Since 1.16 readers may comment (readers_can_comment): the comment actions have their own tests.
            if (!in_array($case[0], ['board', 'listView', 'ganttView', 'task', 'taskPanel', 'addComment', 'editComment', 'deleteComment'], true)) {
                yield $name => $case;
            }
        }
    }

    /**
     * A user with read-only access (BOARD_ACCESS_CHECK grant + markReadOnly) may open the board but gets 403 on
     * every board and task write (spec 171 security fix).
     *
     * @param list<string> $arguments
     * @param array<string, mixed> $post
     */
    #[DataProvider('writeActions')]
    public function testReadOnlyViewerCannotWrite(string $action, array $arguments, array $post): void
    {
        $controller = $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher());
        $request    = Request::create('/', 'POST', $post + ['_token' => 'ok']);
        $args       = array_map(fn (string $argument): mixed => match ($argument) {
            'board'   => $this->board->getId(),
            'task'    => $this->task->getId(),
            'column'  => $this->column->getId(),
            'request' => $request,
            default   => 'some-id',
        }, $arguments);

        $this->expectException(AccessDeniedException::class);
        $controller->{$action}(...$args);
    }

    public function testReadOnlyViewerCanOpenTheBoardWithoutWriteControls(): void
    {
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher())->board($this->board->getId());

        self::assertFalse($this->rendered['canEditBoard']);
    }

    private function readOnlyDispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TaskBoardEvents::BOARD_ACCESS_CHECK, static function (BoardAccessCheckEvent $event): void {
            $event->grant();
            $event->markReadOnly();
        });

        return $dispatcher;
    }

    public function testUnknownBoardAndTaskReturnNotFound(): void
    {
        $controller = $this->controller($this->attacker);

        try {
            $controller->board('missing');
            self::fail('NotFoundHttpException expected');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(NotFoundHttpException::class);
        $controller->task('missing', Request::create('/'));
    }

    public function testIndexOnlyListsAccessibleBoards(): void
    {
        $mine   = new TaskBoard('Mine', 'mine', $this->attacker);
        $boards = [$this->board, $mine];

        $this->controller($this->attacker, $boards)->index();

        self::assertSame([$mine], $this->rendered['boards']);
    }

    public function testIndexIsEmptyWithoutListRole(): void
    {
        $this->controller($this->owner, [$this->board], listRoles: ['ROLE_BOARD_LISTER'])->index();

        self::assertSame([], $this->rendered['boards']);
    }

    public function testOwnerCanOpenBoard(): void
    {
        $this->controller($this->owner)->board($this->board->getId());

        self::assertSame($this->board, $this->rendered['board']);
    }

    public function testMoveToColumnOfAnotherBoardIsBadRequest(): void
    {
        $foreignBoard  = new TaskBoard('Other', 'other', $this->owner);
        $foreignColumn = new BoardColumn($foreignBoard, 'Elsewhere', 0);
        $foreignBoard->addColumn($foreignColumn);

        $request = Request::create('/', 'POST', ['_token' => 'ok', 'columnId' => $foreignColumn->getId(), 'position' => '0']);

        try {
            $this->controller($this->owner)->moveTask($this->task->getId(), $request);
            self::fail('BadRequestHttpException expected');
        } catch (BadRequestHttpException) {
        }

        self::assertSame($this->column, $this->task->getColumn());
    }

    public function testMoveToOwnColumnRedirects(): void
    {
        $request  = Request::create('/', 'POST', ['_token' => 'ok', 'columnId' => $this->column->getId(), 'position' => '4']);
        $response = $this->controller($this->owner)->moveTask($this->task->getId(), $request);

        self::assertSame(302, $response->getStatusCode());
        // Positions are renumbered 0..n-1 inside the target column (the task is alone in it).
        self::assertSame(0, $this->task->getPosition());
    }

    public function testBoardPreselectsFirstColumnIdForNewTasks(): void
    {
        $this->controller($this->owner)->board($this->board->getId());

        $taskForm = $this->findCreatedForm(TaskFormType::class);
        self::assertInstanceOf(TaskFormData::class, $taskForm['data']);
        self::assertSame($this->column->getId(), $taskForm['data']->columnId);
    }

    public function testDuplicateColumnNamesKeepEveryColumnInChoices(): void
    {
        $second = new BoardColumn($this->board, 'Todo', 1);
        $third  = new BoardColumn($this->board, 'Todo', 2);
        $this->board->addColumn($second)->addColumn($third);

        $this->controller($this->owner)->board($this->board->getId());

        self::assertSame([
            'Todo'     => $this->column->getId(),
            'Todo (2)' => $second->getId(),
            'Todo (3)' => $third->getId(),
        ], $this->findCreatedForm(TaskFormType::class)['options']['column_choices']);
    }

    public function testListAndGanttIncludeCompletedTasks(): void
    {
        $controller = $this->controller($this->owner);
        $controller->listView($this->board->getId());
        $controller->ganttView($this->board->getId());

        self::assertSame([true, true], $this->findByBoardIncludeCompleted);
    }

    /** @return array{type: string, data: mixed, options: array<string, mixed>} */
    private function findCreatedForm(string $type): array
    {
        foreach ($this->createdForms as $created) {
            if ($created['type'] === $type) {
                return $created;
            }
        }

        self::fail('Form ' . $type . ' was not created.');
    }

    public function testTaskPagePassesCsrfActionAndPriorityForms(): void
    {
        $member = new TaskMember($this->task, $this->owner, TaskMemberRole::Assignee);
        $this->task->addMember($member);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory())->task($this->task->getId(), Request::create('/'));

        self::assertInstanceOf(FormView::class, $this->rendered['advanceForm']);
        self::assertSame('token-task_board_task_advance', $this->rendered['advanceForm']->children['_token']->vars['value']);
        self::assertSame('_token', $this->rendered['linkRemoveForm']->children['_token']->vars['full_name']);
        self::assertSame('priority', $this->rendered['priorityForm']->children['priority']->vars['full_name']);
        self::assertSame($this->task->getPriority()->value, $this->rendered['priorityForm']->children['priority']->vars['value']);
        self::assertSame('token-task_board_task_member_remove_' . $member->getId(), $this->rendered['memberRemoveForms'][$member->getId()]->children['_token']->vars['value']);
    }

    /** 1.10.1: the subtask handler builds the compact form the task page renders (title and the hidden column). */
    public function testSubtaskUsesTheCompactForm(): void
    {
        $this->controller($this->owner)->createSubtask($this->task->getId(), Request::create('/', 'POST'));

        self::assertTrue($this->findCreatedForm(TaskFormType::class)['options']['compact']);
    }

    public function testListPassesClientSideFilterForm(): void
    {
        $this->controller($this->owner, realFormFactory: $this->realFormFactory())->listView($this->board->getId());

        $filter = $this->rendered['filterForm'];
        self::assertInstanceOf(FormView::class, $filter);
        self::assertSame('search', $filter->children['search']->vars['attr']['data-nowo-task-board-list-filter-target']);
        self::assertSame('column', $filter->children['column']->vars['attr']['data-nowo-task-board-list-filter-target']);
    }

    public function testPriorityUpdateThroughForm(): void
    {
        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory())
            ->updatePriority($this->task->getId(), Request::create('/', 'POST', ['_token' => 'ok', 'priority' => 'urgent']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(TaskPriority::Urgent, $this->task->getPriority());
    }

    public function testUnknownPriorityIsIgnored(): void
    {
        $before   = $this->task->getPriority();
        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory())
            ->updatePriority($this->task->getId(), Request::create('/', 'POST', ['_token' => 'ok', 'priority' => 'nope']));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, $this->task->getPriority());
    }

    public function testPriorityWithInvalidCsrfIsDenied(): void
    {
        $controller = $this->controller($this->owner, realFormFactory: $this->realFormFactory(false), csrfValid: false);

        $this->expectException(AccessDeniedException::class);
        $controller->updatePriority($this->task->getId(), Request::create('/', 'POST', ['_token' => 'bad', 'priority' => 'high']));
    }

    public function testAdvanceWithExtraButtonValuesIsAccepted(): void
    {
        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory())
            ->advanceTask($this->task->getId(), Request::create('/', 'POST', ['_token' => 'ok', 'action' => 'done']));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * Editor forms (Doctrine / FormKit types) stay mocked; the REQ-TWIG-005 root-less forms use the real factory.
     */
    private function hybridFormFactory(FormFactoryInterface $mocked, FormFactoryInterface $real): FormFactoryInterface
    {
        return new class($mocked, $real) implements FormFactoryInterface {
            public function __construct(
                private readonly FormFactoryInterface $mocked,
                private readonly FormFactoryInterface $real,
            ) {
            }

            public function create(string $type = FormType::class, mixed $data = null, array $options = []): FormInterface
            {
                return $this->mocked->create($type, $data, $options);
            }

            public function createNamed(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormInterface
            {
                return $this->real->createNamed($name, $type, $data, $options);
            }

            public function createForProperty(string $class, string $property, mixed $data = null, array $options = []): FormInterface
            {
                return $this->real->createForProperty($class, $property, $data, $options);
            }

            public function createBuilder(string $type = FormType::class, mixed $data = null, array $options = []): FormBuilderInterface
            {
                return $this->real->createBuilder($type, $data, $options);
            }

            public function createNamedBuilder(string $name, string $type = FormType::class, mixed $data = null, array $options = []): FormBuilderInterface
            {
                return $this->real->createNamedBuilder($name, $type, $data, $options);
            }

            public function createBuilderForProperty(string $class, string $property, mixed $data = null, array $options = []): FormBuilderInterface
            {
                return $this->real->createBuilderForProperty($class, $property, $data, $options);
            }
        };
    }

    private function realFormFactory(bool $csrfValid = true): FormFactoryInterface
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 'token-' . $id));

        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrf))
            ->getFormFactory();
    }

    public function testDeletingAColumnRedirectsAndKeepsTheLastColumn(): void
    {
        $second = new BoardColumn($this->board, 'Done', 1);
        $this->board->addColumn($second);
        $controller = $this->controller($this->owner, realFormFactory: $this->realFormFactory());

        $response = $controller->deleteColumn($this->board->getId(), $second->getId(), Request::create('/', 'POST', ['_token' => 'ok']));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame([$this->column], array_values($this->board->getColumns()->toArray()));

        $controller->deleteColumn($this->board->getId(), $this->column->getId(), Request::create('/', 'POST', ['_token' => 'ok']));
        self::assertTrue($this->board->getColumns()->contains($this->column));

        $this->expectException(NotFoundHttpException::class);
        $controller->deleteColumn($this->board->getId(), 'missing', Request::create('/', 'POST', ['_token' => 'ok']));
    }

    public function testDeletingAColumnNeedsAValidToken(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(false), csrfValid: false)
            ->deleteColumn($this->board->getId(), $this->column->getId(), Request::create('/', 'POST', ['_token' => 'bad']));
    }

    public function testBoardPassesColumnIconsSetsAndThePanelTarget(): void
    {
        $set = new ColumnSet('Mine', $this->owner);
        $this->controller($this->owner, columnSets: $this->columnSets([$set]), currentRequest: Request::create('/board?x=1'))->board($this->board->getId());

        self::assertSame(['tabler:bug'], $this->rendered['columnIcons']);
        self::assertNotNull($this->rendered['columnDeleteForm']);
        self::assertSame('/board?x=1', $this->rendered['panelTargetPath']);
        self::assertSame([$set], $this->rendered['columnSets']);
        self::assertArrayHasKey($set->getId(), $this->rendered['columnSetDeleteForms']);
        self::assertSame(['tabler:bug'], $this->findCreatedForm(BoardColumnFormType::class)['options']['icon_choices']);
    }

    public function testBoardAndListPassOneInlineTaskFormPerColumnToEditors(): void
    {
        $this->controller($this->owner, currentRequest: Request::create('/board'))->board($this->board->getId());
        $columnIds = array_map(static fn (BoardColumn $column): string => $column->getId(), $this->board->getColumns()->toArray());
        self::assertSame($columnIds, array_keys($this->rendered['inlineTaskForms']));
        $inline = array_values(array_filter($this->createdForms, static fn (array $form): bool => ($form['options']['compact'] ?? false) === true));
        self::assertCount(count($columnIds), $inline);
        self::assertSame('/redirect', $inline[0]['options']['action']);

        $this->controller($this->owner)->listView($this->board->getId());
        self::assertTrue($this->rendered['canEditBoard']);
        self::assertSame($columnIds, array_keys($this->rendered['inlineTaskForms']));

        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher())->listView($this->board->getId());
        self::assertSame([], $this->rendered['inlineTaskForms']);
    }

    public function testInlineNewTaskUsesTheCompactFormAndComesBack(): void
    {
        $request  = Request::create('/create?inline=1&_target_path=/tools/task-board/board/x/list', 'POST');
        $response = $this->controller($this->owner, formData: new TaskFormData(title: 'Inline'), currentRequest: $request)->createTask($this->board->getId(), $request);
        self::assertSame('/tools/task-board/board/x/list', $response->getTargetUrl());
        self::assertTrue($this->findCreatedForm(TaskFormType::class)['options']['compact']);

        $unsafe   = Request::create('/create?inline=1&_target_path=//evil.example', 'POST');
        $response = $this->controller($this->owner, formData: new TaskFormData(title: 'Inline'), currentRequest: $unsafe)->createTask($this->board->getId(), $unsafe);
        self::assertSame('/redirect', $response->getTargetUrl());
    }

    public function testReadOnlyBoardHasNoColumnSetControls(): void
    {
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), columnSets: $this->columnSets([new ColumnSet('Other')]))->board($this->board->getId());

        self::assertNull($this->rendered['columnDeleteForm']);
        self::assertArrayNotHasKey('columnSets', $this->rendered);
    }

    public function testOnlyTheCreatorSeesTheDeleteFormOfASet(): void
    {
        $theirs = new ColumnSet('Theirs', $this->attacker);
        $this->controller($this->owner, columnSets: $this->columnSets([$theirs]))->board($this->board->getId());

        self::assertSame([], $this->rendered['columnSetDeleteForms']);
    }

    public function testColumnSetRoutesAreNotFoundWithoutTheService(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller($this->owner)->createColumnSet($this->board->getId(), Request::create('/', 'POST'));
    }

    public function testSavingTheColumnsAsASet(): void
    {
        $repository = $this->createMock(ColumnSetRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::callback(static fn (ColumnSet $set): bool => $set->getName() === 'Flow' && $set->getItems() === [['name' => 'Todo', 'color' => null, 'icon' => null]]));

        $response = $this->controller($this->owner, columnSets: new ColumnSetManager($repository, $this->createMock(BoardColumnRepositoryInterface::class)), formData: new ColumnSetFormData('Flow'))
            ->createColumnSet($this->board->getId(), Request::create('/', 'POST'));

        self::assertSame(302, $response->getStatusCode());
    }

    public function testApplyingASetAppendsItsColumns(): void
    {
        $set = (new ColumnSet('Bugs'))->addItem('Triage');

        $this->controller($this->owner, columnSets: $this->columnSets([$set]), formData: new ColumnSetApplyFormData($set->getId()))
            ->applyColumnSet($this->board->getId(), Request::create('/', 'POST'));
        self::assertCount(2, $this->board->getColumns());
        self::assertSame(['Bugs' => $set->getId()], $this->findCreatedForm(ColumnSetApplyFormType::class)['options']['column_set_choices']);

        // A set outside the user's scope is ignored.
        $this->controller($this->owner, columnSets: $this->columnSets([$set]), formData: new ColumnSetApplyFormData('other'))
            ->applyColumnSet($this->board->getId(), Request::create('/', 'POST'));
        self::assertCount(2, $this->board->getColumns());
    }

    public function testDeletingASetIsForItsCreator(): void
    {
        $mine       = new ColumnSet('Mine', $this->owner);
        $theirs     = new ColumnSet('Theirs', $this->attacker);
        $repository = $this->createMock(ColumnSetRepositoryInterface::class);
        $repository->method('findByScope')->willReturn([$mine, $theirs]);
        $repository->expects(self::once())->method('remove')->with($mine);
        $controller = $this->controller($this->owner, realFormFactory: $this->realFormFactory(), columnSets: new ColumnSetManager($repository, $this->createMock(BoardColumnRepositoryInterface::class)));

        self::assertSame(302, $controller->deleteColumnSet($this->board->getId(), $mine->getId(), Request::create('/', 'POST', ['_token' => 'ok']))->getStatusCode());

        try {
            $controller->deleteColumnSet($this->board->getId(), 'missing', Request::create('/', 'POST', ['_token' => 'ok']));
            self::fail('NotFoundHttpException expected');
        } catch (NotFoundHttpException) {
        }

        $this->expectException(AccessDeniedException::class);
        $controller->deleteColumnSet($this->board->getId(), $theirs->getId(), Request::create('/', 'POST', ['_token' => 'ok']));
    }

    public function testNewBoardFromASetUsesItsColumns(): void
    {
        $set = (new ColumnSet('Bugs'))->addItem('Triage')->addItem('Fixed');

        $this->controller($this->owner, columnSets: $this->columnSets([$set]))->index();
        self::assertSame(['Bugs' => $set->getId()], $this->findCreatedForm(TaskBoardFormType::class)['options']['column_set_choices']);

        $response = $this->controller($this->owner, columnSets: $this->columnSets([$set]), formData: new TaskBoardFormData(name: 'Bugs', columnSetId: $set->getId()))
            ->createBoard(Request::create('/', 'POST'));
        self::assertSame(302, $response->getStatusCode());

        $response = $this->controller($this->owner, formData: new TaskBoardFormData(name: 'Plain'))->createBoard(Request::create('/', 'POST'));
        self::assertSame(302, $response->getStatusCode());
    }

    public function testDuplicateSetNamesKeepEverySetInChoices(): void
    {
        $a = new ColumnSet('Kanban');
        $b = new ColumnSet('Kanban');
        $this->controller($this->owner, columnSets: $this->columnSets([$a, $b]))->index();

        self::assertSame(['Kanban' => $a->getId(), 'Kanban (2)' => $b->getId()], $this->findCreatedForm(TaskBoardFormType::class)['options']['column_set_choices']);
    }

    public function testTaskPanelRendersTheFragmentWithASafeTarget(): void
    {
        $controller = $this->controller($this->owner, realFormFactory: $this->realFormFactory());

        $controller->taskPanel($this->task->getId(), Request::create('/', 'GET', ['_target_path' => '/projects/1/tasks/board/2?view=list']));
        self::assertSame('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->renderedTemplate);
        self::assertTrue($this->rendered['panel']);
        self::assertSame('/projects/1/tasks/board/2?view=list', $this->rendered['panelTargetPath']);
        self::assertSame(['_target_path' => '/projects/1/tasks/board/2?view=list'], $this->rendered['taskRouteExtra']);

        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', "/a\nb", ''] as $unsafe) {
            $controller->taskPanel($this->task->getId(), Request::create('/', 'GET', ['_target_path' => $unsafe]));
            self::assertSame('/redirect', $this->rendered['panelTargetPath'], $unsafe);
        }
    }

    public function testTaskWritesComeBackToTheirTarget(): void
    {
        $controller = $this->controller($this->owner, realFormFactory: $this->realFormFactory());

        $response = $controller->createSubtask($this->task->getId(), Request::create('/?_target_path=/board/1', 'POST'));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/board/1', $response->getTargetUrl());

        $response = $controller->createSubtask($this->task->getId(), Request::create('/?_target_path=//evil.example', 'POST'));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/redirect', $response->getTargetUrl());

        $response = $controller->advanceTask($this->task->getId(), Request::create('/', 'POST', ['_token' => 'ok', 'action' => 'nope', '_target_path' => '/list']));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/list', $response->getTargetUrl());
    }

    public function testFetchedTaskWritesAnswerThePanel(): void
    {
        $request = Request::create('/?_target_path=/board/1', 'POST', ['_token' => 'ok', 'priority' => 'urgent']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory())->updatePriority($this->task->getId(), $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/board/1', $this->rendered['panelTargetPath']);
        self::assertSame(TaskPriority::Urgent, $this->task->getPriority());
    }

    public function testFetchedTaskPageAnswersThePanelAndInvalidSavesAre422(): void
    {
        $get = Request::create('/');
        $get->headers->set('Turbo-Frame', 'task-panel');
        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory())->task($this->task->getId(), $get);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->rendered['panel']);

        $post = Request::create('/', 'POST');
        $post->headers->set('X-Requested-With', 'XMLHttpRequest');
        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory(), formData: new TaskFormData(title: ''), formValid: false)->task($this->task->getId(), $post);
        self::assertSame(422, $response->getStatusCode());

        $response = $this->controller($this->owner, realFormFactory: $this->realFormFactory(), formData: new TaskFormData(title: 'Renamed'))->task($this->task->getId(), Request::create('/', 'POST'));
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('Renamed', $this->task->getTitle());
    }

    /**
     * @param list<ColumnSet> $sets
     */
    private function columnSets(array $sets): ColumnSetManager
    {
        $repository = $this->createMock(ColumnSetRepositoryInterface::class);
        $repository->method('findByScope')->willReturn($sets);

        return new ColumnSetManager($repository, $this->createMock(BoardColumnRepositoryInterface::class));
    }

    public function testNestedColumnOrderIsBadRequest(): void
    {
        $request = Request::create('/', 'POST', ['_token' => 'ok', 'columnOrder' => [['x'], $this->column->getId()]]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller($this->owner)->reorderColumns($this->board->getId(), $request);
    }

    public function testFlatColumnOrderIsAccepted(): void
    {
        $request  = Request::create('/', 'POST', ['_token' => 'ok', 'columnOrder' => [$this->column->getId()]]);
        $response = $this->controller($this->owner)->reorderColumns($this->board->getId(), $request);

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * Since 1.18: import subtasks into a task — no file, a bad file and a good one (subtasks under the task).
     */
    public function testImportingSubtasksIntoATaskFlashesTheOutcome(): void
    {
        $request = Request::create('/?_target_path=/board', 'POST');
        $this->controller($this->owner, formData: new TaskImportFormData(TaskImportSource::TaskBoardCsv), currentRequest: $request)->importIntoTask($this->task->getId(), $request);
        self::assertSame(['task_board.flash.import_file_required'], $request->getSession()->getFlashBag()->get('error'));

        $request = Request::create('/?_target_path=/board', 'POST');
        $this->controller($this->owner, formData: new TaskImportFormData(TaskImportSource::TaskBoardCsv, $this->upload("title;start\nA;soon")), currentRequest: $request)->importIntoTask($this->task->getId(), $request);
        self::assertStringContainsString('is not a date', (string) ($request->getSession()->getFlashBag()->get('error')[0] ?? ''));

        $request  = Request::create('/?_target_path=/board', 'POST');
        $response = $this->controller($this->owner, formData: new TaskImportFormData(TaskImportSource::TaskBoardCsv, $this->upload("id;parent;title\na;;Child\nb;zzz;Orphan")), currentRequest: $request, translator: true)
            ->importIntoTask($this->task->getId(), $request);
        self::assertInstanceOf(RedirectResponse::class, $response);
        $flashes = $request->getSession()->getFlashBag()->all();
        self::assertSame(['task_board.flash.subtask_import_summary'], $flashes['success'] ?? []);
        self::assertCount(1, $flashes['warning'] ?? []);
    }

    private function upload(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tb-import-');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'plan.csv', 'text/csv', null, true);
    }

    /**
     * Since 1.15: add a dependency (flash per outcome), remove it, and refuse a CSRF failure.
     */
    public function testAddingADependencyFlashesTheOutcome(): void
    {
        $other            = new Task($this->board, 'Other', $this->owner, column: $this->column);
        $foreign          = new Task(new TaskBoard('Foreign', 'foreign', $this->owner), 'Foreign', $this->owner);
        $this->extraTasks = [$other, $foreign];

        $cases = [
            'task_board.flash.dependency_added'       => new TaskDependencyFormData(TaskDependencyType::Blocks, $other->getId()),
            'task_board.flash.dependency_cycle'       => new TaskDependencyFormData(TaskDependencyType::BlockedBy, $other->getId()),
            'task_board.flash.dependency_self'        => new TaskDependencyFormData(TaskDependencyType::Blocks, $this->task->getId()),
            'task_board.flash.dependency_other_board' => new TaskDependencyFormData(TaskDependencyType::Blocks, $foreign->getId()),
            'task_board.flash.dependency_invalid'     => new TaskDependencyFormData(TaskDependencyType::Blocks, 'missing'),
        ];
        foreach ($cases as $flash => $data) {
            $request  = Request::create('/?_target_path=/board', 'POST');
            $response = $this->controller($this->owner, formData: $data, currentRequest: $request)->addDependency($this->task->getId(), $request);
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('/board', $response->getTargetUrl());
            self::assertSame([$flash], array_merge(...array_values($request->getSession()->getFlashBag()->all())), $flash);
        }

        self::assertCount(1, $this->task->getOutgoingDependencies());
        $dependency = $this->task->getOutgoingDependencies()->first();
        self::assertNotFalse($dependency);

        // An unsubmitted form changes nothing.
        $this->controller($this->owner)->addDependency($this->task->getId(), Request::create('/', 'POST'));

        $request = Request::create('/', 'POST', ['_token' => 'ok']);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(), currentRequest: $request)->removeDependency($this->task->getId(), $dependency->getId(), $request);
        self::assertSame(['task_board.flash.dependency_removed'], $request->getSession()->getFlashBag()->get('success'));
        self::assertTrue($this->task->getOutgoingDependencies()->isEmpty());
        self::assertTrue($other->getIncomingDependencies()->isEmpty());

        $request = Request::create('/', 'POST', ['_token' => 'ok']);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(), currentRequest: $request)->removeDependency($this->task->getId(), 'missing', $request);
        self::assertSame([], $request->getSession()->getFlashBag()->all());
    }

    public function testDependencyAndTimeWritesRefuseAnInvalidToken(): void
    {
        foreach (['addDependency', 'logTime'] as $action) {
            try {
                $this->controller($this->owner, formData: new TaskDependencyFormData(), rootError: true)->{$action}($this->task->getId(), Request::create('/', 'POST'));
                self::fail('AccessDeniedException expected for ' . $action);
            } catch (AccessDeniedException) {
            }
        }

        $this->expectException(AccessDeniedException::class);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(false), csrfValid: false)->removeTimeEntry($this->task->getId(), 'x', Request::create('/', 'POST', ['_token' => 'bad']));
    }

    public function testARefusedTaskSaysWhyInAFlash(): void
    {
        $request = Request::create('/', 'POST');
        $this->controller($this->owner, formData: new TaskFormData(title: str_repeat('x', 300)), formValid: false, currentRequest: $request, translator: true)->createTask($this->board->getId(), $request);
        self::assertSame(['task_board.flash.task_invalid'], $request->getSession()->getFlashBag()->get('error'));

        $request = Request::create('/', 'POST');
        $this->controller($this->owner, formData: new TaskFormData(title: ''), formValid: false, currentRequest: $request, translator: true)->createSubtask($this->task->getId(), $request);
        self::assertSame(['task_board.flash.task_invalid'], $request->getSession()->getFlashBag()->get('error'));
    }

    /**
     * Since 1.16: a reader comments (readers_can_comment), only the author edits, the author or an editor deletes.
     */
    public function testCommentsByReadersAuthorsAndEditors(): void
    {
        $request = Request::create('/', 'POST');
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData('Hi @owner', '[{"id":"1","label":"owner"}]'), currentRequest: $request)->addComment($this->task->getId(), $request);
        self::assertSame(['task_board.flash.comment_added'], $request->getSession()->getFlashBag()->get('success'));
        $comment = array_values($this->comments)[0];
        self::assertSame(['1'], $comment->getMentionedIds());

        // An invalid form, an empty body, an unsubmitted form.
        $request = Request::create('/', 'POST');
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData(''), formValid: false, currentRequest: $request)->addComment($this->task->getId(), $request);
        self::assertSame(['task_board.flash.comment_invalid'], $request->getSession()->getFlashBag()->get('error'));
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData('   '), currentRequest: $request)->addComment($this->task->getId(), $request);
        self::assertSame(['task_board.flash.comment_invalid'], $request->getSession()->getFlashBag()->get('error'));
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher())->addComment($this->task->getId(), Request::create('/', 'POST'));
        self::assertCount(1, $this->comments);

        // The author edits; the owner (an editor) may not edit someone else's comment, but may delete it.
        $request = Request::create('/', 'POST');
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData('Edited'), currentRequest: $request)->editComment($this->task->getId(), $comment->getId(), $request);
        self::assertSame('Edited', $comment->getBody());
        self::assertSame(['task_board.flash.comment_updated'], $request->getSession()->getFlashBag()->get('success'));
        try {
            $this->controller($this->owner, formData: new TaskCommentFormData('Hijack'))->editComment($this->task->getId(), $comment->getId(), Request::create('/', 'POST'));
            self::fail('AccessDeniedException expected');
        } catch (AccessDeniedException) {
        }
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher())->editComment($this->task->getId(), $comment->getId(), Request::create('/', 'POST'));

        $request = Request::create('/', 'POST', ['_token' => 'ok']);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(), currentRequest: $request)->deleteComment($this->task->getId(), $comment->getId(), $request);
        self::assertTrue($comment->isDeleted());
        self::assertSame(['task_board.flash.comment_deleted'], $request->getSession()->getFlashBag()->get('success'));

        // A deleted or unknown comment is not found.
        $this->expectException(NotFoundHttpException::class);
        $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData('again'))->editComment($this->task->getId(), $comment->getId(), Request::create('/', 'POST'));
    }

    public function testReadersCannotCommentWhenTheOptionIsOffNorDeleteOthersComments(): void
    {
        try {
            $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData('x'), readersCanComment: false)->addComment($this->task->getId(), Request::create('/', 'POST'));
            self::fail('AccessDeniedException expected');
        } catch (AccessDeniedException) {
        }

        $byOwner                           = new TaskComment($this->task, $this->owner, 'Owner note');
        $this->comments[$byOwner->getId()] = $byOwner;
        try {
            $this->controller($this->attacker, realFormFactory: $this->realFormFactory(), dispatcher: $this->readOnlyDispatcher())->deleteComment($this->task->getId(), $byOwner->getId(), Request::create('/', 'POST', ['_token' => 'ok']));
            self::fail('AccessDeniedException expected');
        } catch (AccessDeniedException) {
        }

        try {
            $this->controller($this->attacker, dispatcher: $this->readOnlyDispatcher(), formData: new TaskCommentFormData('x'), rootError: true)->addComment($this->task->getId(), Request::create('/', 'POST'));
            self::fail('AccessDeniedException expected');
        } catch (AccessDeniedException) {
        }

        $other                             = new Task(new TaskBoard('Other', 'other', $this->owner), 'Elsewhere', $this->owner);
        $foreign                           = new TaskComment($other, $this->owner, 'x');
        $this->comments[$foreign->getId()] = $foreign;
        $this->expectException(NotFoundHttpException::class);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory())->deleteComment($this->task->getId(), $foreign->getId(), Request::create('/', 'POST', ['_token' => 'ok']));
    }

    public function testTheTaskViewPassesTheCommentsTheBoxAndTheCandidates(): void
    {
        $mine   = new TaskComment($this->task, $this->owner, 'Mine');
        $theirs = new TaskComment($this->task, $this->attacker, 'Theirs');
        $gone   = (new TaskComment($this->task, $this->attacker, 'Gone'))->markDeleted();
        foreach ([$mine, $theirs, $gone] as $comment) {
            $this->comments[$comment->getId()] = $comment;
        }

        $this->controller($this->owner, realFormFactory: $this->realFormFactory())->task($this->task->getId(), Request::create('/'));

        self::assertCount(3, $this->rendered['comments']);
        self::assertInstanceOf(FormView::class, $this->rendered['commentForm']);
        self::assertSame([$mine->getId()], array_keys($this->rendered['commentEditForms']));
        self::assertSame([$mine->getId() => true, $theirs->getId() => true], $this->rendered['commentDeletable']);
        self::assertSame([['id' => '1', 'label' => 'owner'], ['id' => '2', 'label' => 'attacker']], $this->rendered['mentionCandidates']);
        self::assertSame('1', $this->rendered['viewerId']);

        $this->controller($this->attacker, realFormFactory: $this->realFormFactory(), dispatcher: $this->readOnlyDispatcher(), readersCanComment: false)->task($this->task->getId(), Request::create('/'));
        self::assertNull($this->rendered['commentForm']);
        self::assertSame([], $this->rendered['mentionCandidates']);
        self::assertSame([$mine->getId() => false, $theirs->getId() => true], $this->rendered['commentDeletable']);

        $this->controller($this->owner)->board($this->board->getId());
        self::assertSame([$this->task->getId() => 2], $this->rendered['commentCounts']);
        $this->controller($this->owner)->listView($this->board->getId());
        self::assertSame([$this->task->getId() => 2], $this->rendered['commentCounts']);
    }

    public function testLoggingAndRemovingTime(): void
    {
        $request = Request::create('/', 'POST');
        $this->controller($this->owner, formData: new TaskTimeEntryFormData(45, new DateTimeImmutable('2026-10-28'), 'Review'), currentRequest: $request)->logTime($this->task->getId(), $request);
        self::assertSame(['task_board.flash.time_logged'], $request->getSession()->getFlashBag()->get('success'));
        self::assertSame(45, $this->task->getLoggedMinutes());

        $request = Request::create('/', 'POST');
        $this->controller($this->owner, formData: new TaskTimeEntryFormData(), formValid: false, currentRequest: $request)->logTime($this->task->getId(), $request);
        self::assertSame(['task_board.flash.time_invalid'], $request->getSession()->getFlashBag()->get('error'));

        $this->controller($this->owner)->logTime($this->task->getId(), Request::create('/', 'POST'));

        $entry = $this->task->getTimeEntries()->first();
        self::assertNotFalse($entry);
        $request = Request::create('/', 'POST', ['_token' => 'ok']);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(), currentRequest: $request)->removeTimeEntry($this->task->getId(), $entry->getId(), $request);
        self::assertSame(['task_board.flash.time_removed'], $request->getSession()->getFlashBag()->get('success'));
        self::assertSame(0, $this->task->getLoggedMinutes());

        $request = Request::create('/', 'POST', ['_token' => 'ok']);
        $this->controller($this->owner, realFormFactory: $this->realFormFactory(), currentRequest: $request)->removeTimeEntry($this->task->getId(), 'missing', $request);
        self::assertSame([], $request->getSession()->getFlashBag()->all());
    }

    public function testTaskViewPassesTheDependencyAndTimeFormsAndTheDeviation(): void
    {
        $child            = new Task($this->board, 'Child', $this->owner, parent: $this->task, estimatedMinutes: 30);
        $this->extraTasks = [$child];

        $this->controller($this->owner, realFormFactory: $this->realFormFactory())->task($this->task->getId(), Request::create('/'));

        self::assertInstanceOf(FormView::class, $this->rendered['dependencyForm']);
        self::assertInstanceOf(FormView::class, $this->rendered['timeEntryForm']);
        self::assertSame(['Child' => $child->getId()], $this->findCreatedForm(TaskDependencyFormType::class)['options']['task_choices']);
        self::assertSame(30, $this->rendered['subtaskDeviation']->estimatedMinutes);
        self::assertNull($this->rendered['deviation']->estimatedMinutes);
    }

    public function testGanttUsesTheScaleOfTheQueryAndTheWeekStart(): void
    {
        $request = Request::create('/?scale=weeks');
        $this->controller($this->owner, currentRequest: $request)->ganttView($this->board->getId());

        self::assertSame(GanttScale::Weeks, $this->rendered['scale']);
        self::assertSame(GanttScale::cases(), $this->rendered['scales']);
        self::assertSame(WeekStart::Sunday, $this->rendered['timeline']->weekStart);

        $this->controller($this->owner, currentRequest: Request::create('/?scale=years'))->ganttView($this->board->getId());
        self::assertSame(GanttScale::Days, $this->rendered['scale']);
    }

    private function commentRepository(): TaskCommentRepositoryInterface
    {
        if ($this->commentRepositoryMock instanceof TaskCommentRepositoryInterface) {
            return $this->commentRepositoryMock;
        }

        $repository = $this->createMock(TaskCommentRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (TaskComment $comment): void {
            $this->comments[$comment->getId()] = $comment;
        });
        $repository->method('findById')->willReturnCallback(fn (string $id): ?TaskComment => $this->comments[$id] ?? null);
        $repository->method('findByTask')->willReturnCallback(fn (Task $task): array => array_values(array_filter($this->comments, static fn (TaskComment $c): bool => $c->getTask() === $task)));
        $repository->method('countByTasks')->willReturnCallback(fn (array $ids): array => array_count_values(array_map(static fn (TaskComment $c): string => $c->getTask()->getId(), array_filter($this->comments, static fn (TaskComment $c): bool => !$c->isDeleted() && in_array($c->getTask()->getId(), $ids, true)))));

        return $this->commentRepositoryMock = $repository;
    }

    private function mentionCandidates(): TaskMentionCandidatesResolverInterface
    {
        $resolver = $this->createMock(TaskMentionCandidatesResolverInterface::class);
        $resolver->method('candidates')->willReturnCallback(fn (): array => [new MentionCandidate('1', 'owner', $this->owner), new MentionCandidate('2', 'attacker', $this->attacker)]);

        return $resolver;
    }

    private function identityTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    /**
     * @param list<TaskBoard> $boards
     * @param list<string> $listRoles
     */
    private function controller(TestUser $user, array $boards = [], array $listRoles = ['ROLE_USER'], ?FormFactoryInterface $realFormFactory = null, bool $csrfValid = true, ?EventDispatcher $dispatcher = null, ?ColumnSetManager $columnSets = null, mixed $formData = null, bool $formValid = true, ?Request $currentRequest = null, bool $rootError = false, bool $translator = false, bool $readersCanComment = true): TaskBoardManageController
    {
        $boardRepository = $this->createMock(TaskBoardRepositoryInterface::class);
        $boardRepository->method('findById')->willReturnCallback(fn (string $id): ?TaskBoard => $id === $this->board->getId() ? $this->board : null);
        $boardRepository->method('findAllActive')->willReturn($boards);

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findById')->willReturnCallback(function (string $id): ?Task {
            foreach ([$this->task, ...$this->extraTasks] as $task) {
                if ($task->getId() === $id) {
                    return $task;
                }
            }

            return null;
        });
        $taskRepository->method('findByBoard')->willReturnCallback(function (TaskBoard $board, bool $includeCompleted = false): array {
            $this->findByBoardIncludeCompleted[] = $includeCompleted;

            return array_values(array_filter([$this->task, ...$this->extraTasks], static fn (Task $task): bool => $task->getBoard() === $board));
        });

        $teamMembers = $this->createMock(TeamMemberRepositoryInterface::class);
        $teamMembers->method('findByUserId')->willReturn([]);

        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => $role === 'ROLE_USER');

        $recorder      = new TaskChangeRecorder();
        $columnManager = new BoardColumnManager($this->createMock(BoardColumnRepositoryInterface::class));

        $controller = new TaskBoardManageController(
            accessChecker: new ConfigurableTaskBoardAccessChecker($authorization, ['ROLE_USER'], ['ROLE_USER'], $listRoles),
            boardRepository: $boardRepository,
            taskRepository: $taskRepository,
            boardCreator: new TaskBoardCreator($boardRepository),
            columnManager: $columnManager,
            taskManager: new TaskManager($taskRepository, $recorder),
            linkAttacher: new TaskLinkAttacher($taskRepository, new GitLabMergeRequestLinkParser(), $recorder),
            memberAssigner: new TaskMemberAssigner($taskRepository, $recorder),
            ganttBuilder: new TaskGanttBuilder(),
            importOrchestrator: new TaskImportOrchestrator([new TaskBoardCsvImporter()], $taskRepository, $columnManager, $recorder, $this->createMock(TaskImportUserResolverInterface::class), $this->createMock(EntityManagerInterface::class)),
            translator: $translator ? $this->identityTranslator() : $this->createMock(TranslatorInterface::class),
            routes: array_fill_keys(['index', 'board', 'task', 'board_import', 'task_member_remove', 'task_priority', 'column_set_delete', 'task_create'], ['path' => '/', 'name' => 'route']),
            templates: array_fill_keys(['index', 'board', 'list', 'gantt', 'task', 'import'], 'template.html.twig'),
            userClass: TestUser::class,
            dashboardRoute: null,
            resourceAccessChecker: new TaskBoardResourceAccessChecker($teamMembers, new NullTaskBoardTeamMembershipResolver(), $authorization, $dispatcher),
            columnSetManager: $columnSets,
            columnIcons: ['tabler:bug'],
            dependencyManager: new TaskDependencyManager($taskRepository, $recorder),
            timeLogger: new TaskTimeLogger($taskRepository, $recorder),
            weekStartResolver: new ConfiguredWeekStartResolver('sunday'),
            commentManager: new TaskCommentManager($this->commentRepository(), $candidates = $this->mentionCandidates()),
            commentRepository: $this->commentRepository(),
            mentionCandidates: $candidates,
            readersCanComment: $readersCanComment,
        );

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 'token-' . $id));

        $form = $this->createMock(FormInterface::class);
        $form->method('isSubmitted')->willReturn($formData !== null);
        $form->method('isValid')->willReturn($formValid);
        $form->method('getData')->willReturn($formData);
        $form->method('getErrors')->willReturnCallback(static fn (bool $deep = false): FormErrorIterator => new FormErrorIterator($form, $rootError || $deep ? [new FormError('Invalid CSRF token.')] : []));
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturnCallback(function (string $type, mixed $data = null, array $options = []) use ($form): FormInterface {
            $this->createdForms[] = ['type' => $type, 'data' => $data, 'options' => $options];

            return $form;
        });

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context): string {
            $this->rendered         = $context;
            $this->renderedTemplate = $template;

            return '';
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/redirect');

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('form.factory', $realFormFactory instanceof FormFactoryInterface ? $this->hybridFormFactory($formFactory, $realFormFactory) : $formFactory);
        $request = $currentRequest ?? new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $container->set('request_stack', $requestStack);
        $container->set('twig', $twig);
        $container->set('router', $router);
        $controller->setContainer($container);

        return $controller;
    }
}
