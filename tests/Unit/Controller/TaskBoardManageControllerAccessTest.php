<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\GitLabMergeRequestLinkParser;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

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

    /** @var list<array{type: string, data: mixed, options: array<string, mixed>}> */
    private array $createdForms = [];

    /** @var list<bool> */
    private array $findByBoardIncludeCompleted = [];

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
     * @param list<TaskBoard> $boards
     * @param list<string> $listRoles
     */
    private function controller(TestUser $user, array $boards = [], array $listRoles = ['ROLE_USER']): TaskBoardManageController
    {
        $boardRepository = $this->createMock(TaskBoardRepositoryInterface::class);
        $boardRepository->method('findById')->willReturnCallback(fn (string $id): ?TaskBoard => $id === $this->board->getId() ? $this->board : null);
        $boardRepository->method('findAllActive')->willReturn($boards);

        $taskRepository = $this->createMock(TaskRepositoryInterface::class);
        $taskRepository->method('findById')->willReturnCallback(fn (string $id): ?Task => $id === $this->task->getId() ? $this->task : null);
        $taskRepository->method('findByBoard')->willReturnCallback(function (TaskBoard $board, bool $includeCompleted = false): array {
            $this->findByBoardIncludeCompleted[] = $includeCompleted;

            return [$this->task];
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
            importOrchestrator: new TaskImportOrchestrator([], $taskRepository, $columnManager, $recorder, $this->createMock(TaskImportUserResolverInterface::class), $this->createMock(EntityManagerInterface::class)),
            translator: $this->createMock(TranslatorInterface::class),
            routes: array_fill_keys(['index', 'board', 'task', 'board_import'], ['path' => '/', 'name' => 'route']),
            templates: array_fill_keys(['index', 'board', 'list', 'gantt', 'task', 'import'], 'template.html.twig'),
            userClass: TestUser::class,
            dashboardRoute: null,
            resourceAccessChecker: new TaskBoardResourceAccessChecker($teamMembers, new NullTaskBoardTeamMembershipResolver(), $authorization),
        );

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $form        = $this->createMock(FormInterface::class);
        $formFactory = $this->createMock(FormFactoryInterface::class);
        $formFactory->method('create')->willReturnCallback(function (string $type, mixed $data = null, array $options = []) use ($form): FormInterface {
            $this->createdForms[] = ['type' => $type, 'data' => $data, 'options' => $options];

            return $form;
        });

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $context): string {
            $this->rendered = $context;

            return '';
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/redirect');

        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('form.factory', $formFactory);
        $container->set('twig', $twig);
        $container->set('router', $router);
        $controller->setContainer($container);

        return $controller;
    }
}
