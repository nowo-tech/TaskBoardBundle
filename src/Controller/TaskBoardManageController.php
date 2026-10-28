<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskDependencyFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Dto\TaskTimeEntryFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetApplyFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskDependencyFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Form\TaskMemberFormType;
use Nowo\TaskBoardBundle\Form\TaskPriorityFormType;
use Nowo\TaskBoardBundle\Form\TaskTimeEntryFormType;
use Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function is_object;
use function is_string;
use function preg_match;
use function reset;
use function sprintf;
use function str_starts_with;

/**
 * Manage UI for task boards, kanban, list, and task detail.
 */
final class TaskBoardManageController extends AbstractController
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     * @param list<string> $columnIcons `board.column_icons` (since 1.11)
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardRepositoryInterface $boardRepository,
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskBoardCreator $boardCreator,
        private readonly BoardColumnManager $columnManager,
        private readonly TaskManager $taskManager,
        private readonly TaskLinkAttacher $linkAttacher,
        private readonly TaskMemberAssigner $memberAssigner,
        private readonly TaskGanttBuilder $ganttBuilder,
        private readonly TaskImportOrchestrator $importOrchestrator,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly string $userClass,
        private readonly ?string $dashboardRoute,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly bool $topLevelOnly = false,
        private readonly ?ColumnSetManager $columnSetManager = null,
        private readonly array $columnIcons = [],
        // Since 1.15: dependencies, manual time entries and the Gantt week start (defaults when not wired).
        private readonly ?TaskDependencyManager $dependencyManager = null,
        private readonly ?TaskTimeLogger $timeLogger = null,
        private readonly ?WeekStartResolverInterface $weekStartResolver = null,
    ) {
    }

    public function index(): Response
    {
        $user   = $this->requireUser();
        $tenant = $this->tenantContext?->require($user);
        if ($tenant instanceof TaskBoardTenant && $this->tenantContext?->isSingleBoard() === true) {
            $board = $this->boardRepository->findOneByTenant($tenant->key)
                ?? $this->boardCreator->create(new TaskBoardFormData(name: $tenant->label), $user, $tenant->key);

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $board->getId()]);
        }

        $boards = [];
        if ($this->accessChecker->canListBoards($user)) {
            $candidates = $tenant instanceof TaskBoardTenant
                ? $this->boardRepository->findActiveByTenant($tenant->key)
                : $this->boardRepository->findAllActive();
            $boards = $this->resourceAccessChecker->filterBoards($user, $candidates, $tenant?->key);
        }

        return $this->render($this->templates['index'], [
            'boards'         => $boards,
            'boardForm'      => $this->createBoardForm($user, $tenant?->key),
            'dashboardRoute' => $this->dashboardRoute,
            'tenant'         => $tenant,
        ]);
    }

    public function createBoard(Request $request): RedirectResponse
    {
        $user   = $this->requireUser();
        $tenant = $this->tenantContext?->require($user);
        if (!$this->accessChecker->canCreateBoard($user)) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createBoardForm($user, $tenant?->key);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskBoardFormData $data */
            $data = $form->getData();
            // Since 1.11: the columns of a column set the user may apply (another scope's set is ignored).
            $set   = $data->columnSetId !== null ? $this->columnSetManager?->find($data->columnSetId, $user, null, $tenant?->key) : null;
            $board = $this->boardCreator->create($data, $user, $tenant?->key, $set);

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $board->getId()]);
        }

        return $this->redirectToRoute($this->routes['index']['name']);
    }

    public function board(string $boardId): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);

        $canEditBoard  = $this->resourceAccessChecker->canEditBoard($user, $board);
        $columnForm    = $this->createColumnForm(new BoardColumnFormData());
        $columnChoices = $this->buildColumnChoices($board);

        $taskForm = $this->createForm(TaskFormType::class, new TaskFormData(
            // Choices are label => id: the default column is the first *value* (id), not the label.
            columnId: $columnChoices === [] ? null : reset($columnChoices),
        ), ['column_choices' => $columnChoices]);

        $allTasks = $this->taskRepository->findByBoard($board, true);
        $taskTree = TaskTree::fromTasks($allTasks);

        return $this->render($this->templates['board'], [
            'board' => $board,
            // board.top_level_only: subtasks are counted on their parent card instead of having one of their own.
            'tasks'          => $this->topLevelOnly ? $taskTree->roots() : $allTasks,
            'taskTree'       => $taskTree,
            'canEditBoard'   => $canEditBoard,
            'columnForm'     => $columnForm->createView(),
            'editColumnForm' => $this->createColumnForm(new BoardColumnFormData())->createView(),
            'taskForm'       => $taskForm->createView(),
            'dashboardRoute' => $this->dashboardRoute,
            // Since 1.11: column icons, column delete, column sets and the task panel URL of the cards.
            'columnIcons'      => $this->columnIcons,
            'columnDeleteForm' => $canEditBoard ? $this->csrfActionForm('task_board_column_delete')->createView() : null,
            'panelTargetPath'  => $this->currentRequestUri(),
            'inlineTaskForms'  => $canEditBoard ? $this->inlineTaskForms($board, $columnChoices) : [],
        ] + ($canEditBoard ? $this->columnSetViewData($board, $user) : []));
    }

    /**
     * Since 1.11: deletes a column; its tasks move to the first remaining column. The last column is kept.
     */
    public function deleteColumn(string $boardId, string $columnId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_column_delete'));
        $board  = $this->findBoard($boardId, $user, true);
        $column = $this->findColumn($board, $columnId);

        $deleted = $this->columnManager->delete($column);
        $this->addFlash($deleted ? 'success' : 'error', $deleted ? 'task_board.flash.column_deleted' : 'task_board.flash.column_last');

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    /**
     * Since 1.11: saves the board's columns as a named column set.
     */
    public function createColumnSet(string $boardId, Request $request): RedirectResponse
    {
        $user    = $this->requireUser();
        $board   = $this->findBoard($boardId, $user, true);
        $manager = $this->requireColumnSets();

        $form = $this->createForm(ColumnSetFormType::class, new ColumnSetFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ColumnSetFormData $data */
            $data = $form->getData();
            $manager->saveFromBoard($board, $data->name, $user);
            $this->addFlash('success', 'task_board.flash.column_set_saved');
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    /**
     * Since 1.11: appends copies of a column set's columns to the board.
     */
    public function applyColumnSet(string $boardId, Request $request): RedirectResponse
    {
        $user    = $this->requireUser();
        $board   = $this->findBoard($boardId, $user, true);
        $manager = $this->requireColumnSets();

        $form = $this->createForm(ColumnSetApplyFormType::class, new ColumnSetApplyFormData(), [
            'column_set_choices' => $this->columnSetChoices($manager->available($user, $board)),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ColumnSetApplyFormData $data */
            $data = $form->getData();
            $set  = $manager->find((string) $data->columnSetId, $user, $board);
            if ($set instanceof ColumnSet) {
                $manager->applyToBoard($set, $board);
                $this->addFlash('success', 'task_board.flash.column_set_applied');
            }
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    /**
     * Since 1.11: deletes a column set (its creator or a manage role, unless a COLUMN_SET_ACCESS_CHECK listener
     * decides otherwise); boards keep the columns they got from it.
     */
    public function deleteColumnSet(string $boardId, string $setId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_column_set_delete'));
        $board   = $this->findBoard($boardId, $user, true);
        $manager = $this->requireColumnSets();
        $set     = $manager->find($setId, $user, $board) ?? throw new NotFoundHttpException('Column set not found.');

        if (!$this->resourceAccessChecker->canDeleteColumnSet($user, $set)) {
            throw $this->createAccessDeniedException('Column set access denied.');
        }

        $manager->delete($set);
        $this->addFlash('success', 'task_board.flash.column_set_deleted');

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function createColumn(string $boardId, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user, true);

        $form = $this->createColumnForm(new BoardColumnFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BoardColumnFormData $data */
            $data = $form->getData();
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $this->columnManager->add($board, $data);
            $this->addFlash('success', 'task_board.flash.column_added');
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function updateColumn(string $boardId, string $columnId, Request $request): RedirectResponse
    {
        $user   = $this->requireUser();
        $board  = $this->findBoard($boardId, $user, true);
        $column = $this->findColumn($board, $columnId);

        $form = $this->createColumnForm(new BoardColumnFormData(
            name: $column->getName(),
            color: $column->getColor(),
            icon: $column->getIcon(),
        ));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BoardColumnFormData $data */
            $data = $form->getData();
            $this->columnManager->update($column, $data);
            $this->addFlash('success', 'task_board.flash.column_updated');
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function reorderColumns(string $boardId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrf($request, 'task_board_column_reorder');
        $board = $this->findBoard($boardId, $user, true);

        $order = $request->request->all('columnOrder');
        foreach ($order as $columnId) {
            if (!is_string($columnId)) {
                throw new BadRequestHttpException('columnOrder must be a list of column ids.');
            }
        }

        if ($order !== []) {
            $this->columnManager->reorder($board, $order);
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function createTask(string $boardId, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user, true);

        // Since 1.12: `?inline=1` is the inline "+ New task" of a column or a list group — the compact form (title and
        // the hidden column, normal priority) — and comes back to `_target_path` (the board or the list).
        $inline = $request->query->getBoolean('inline');
        $form   = $this->createForm(TaskFormType::class, new TaskFormData(), [
            'column_choices' => $this->buildColumnChoices($board),
            'compact'        => $inline,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskFormData $data */
            $data = $form->getData();
            $this->taskManager->create($board, $data, $user);
        } elseif ($form->isSubmitted()) {
            // Since 1.15: a refused task (e.g. a title over 255 characters) says why instead of vanishing.
            $this->flashFormErrors($form);
        }

        $target = $inline ? $this->safeTargetPath($request) : null;

        return $target !== null ? $this->redirect($target) : $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function importBoard(string $boardId, Request $request): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user, true);

        $form = $this->createForm(TaskImportFormType::class, new TaskImportFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskImportFormData $data */
            $data = $form->getData();
            $file = $data->file;
            if ($file === null) {
                $this->addFlash('error', 'task_board.flash.import_file_required');

                return $this->redirectToRoute($this->routes['board_import']['name'], ['boardId' => $boardId]);
            }

            $result = $this->importOrchestrator->import(
                board: $board,
                source: $data->source,
                content: (string) file_get_contents($file->getPathname()),
                filename: (string) $file->getClientOriginalName(),
                actor: $user,
                options: new TaskImportOptions(
                    createMissingColumns: $data->createMissingColumns,
                    skipExisting: $data->skipExisting,
                ),
            );

            foreach ($result->errors as $error) {
                $this->addFlash('error', $error);
            }

            foreach ($result->warnings as $warning) {
                $this->addFlash('warning', $warning);
            }

            if (!$result->hasErrors()) {
                $this->addFlash('success', $this->translator->trans('task_board.flash.import_summary', [
                    '%created%' => (string) $result->created,
                    '%skipped%' => (string) $result->skipped,
                    '%columns%' => (string) $result->columnsCreated,
                ], 'NowoTaskBoardBundle'));
            }

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
        }

        return $this->render($this->templates['import'], [
            'board'          => $board,
            'importForm'     => $form,
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    public function listView(string $boardId): Response
    {
        $user         = $this->requireUser();
        $board        = $this->findBoard($boardId, $user);
        $canEditBoard = $this->resourceAccessChecker->canEditBoard($user, $board);
        // Completed tasks (last column) stay visible so the column filter can show "Done" work.
        $tasks = $this->taskRepository->findByBoard($board, true);

        $priorities = [];
        $columns    = [];
        foreach ($tasks as $task) {
            $priorities[$task->getPriority()->value] = true;
            $column                                  = $task->getColumn();
            if ($column !== null) {
                $columns[$column->getName()] = true;
            }
        }

        $priorityNames = array_map(strval(...), array_keys($priorities));
        $columnNames   = array_map(strval(...), array_keys($columns));

        return $this->render($this->templates['list'], [
            'board'       => $board,
            'tasks'       => $tasks,
            'taskTree'    => TaskTree::fromTasks($tasks),
            'priorities'  => $priorityNames,
            'columnNames' => $columnNames,
            'filterForm'  => $this->formFactory()->createNamed('', TaskListFilterType::class, null, [
                'priorities'   => $priorityNames,
                'column_names' => $columnNames,
            ])->createView(),
            'dashboardRoute'  => $this->dashboardRoute,
            'panelTargetPath' => $this->currentRequestUri(),
            // Since 1.12: the list groups the tasks by column, each group with its inline "+ New task".
            'canEditBoard'    => $canEditBoard,
            'inlineTaskForms' => $canEditBoard ? $this->inlineTaskForms($board, $this->buildColumnChoices($board)) : [],
        ]);
    }

    public function ganttView(string $boardId): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);
        // Completed tasks are drawn as finished bars (and keep their subtasks attached to the tree).
        $tasks = $this->taskRepository->findByBoard($board, true);
        // Since 1.15: `?scale=hours|days|weeks|months` (default days) and the week start of the request.
        $scale    = GanttScale::fromQuery($this->currentRequest()?->query->get('scale'));
        $timeline = $this->ganttBuilder->build($tasks, $scale, $this->weekStartResolver?->resolve() ?? WeekStart::Monday);

        return $this->render($this->templates['gantt'], [
            'board'           => $board,
            'timeline'        => $timeline,
            'scale'           => $scale,
            'scales'          => GanttScale::cases(),
            'dashboardRoute'  => $this->dashboardRoute,
            'panelTargetPath' => $this->currentRequestUri(),
        ]);
    }

    public function task(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user);

        $taskForm = $this->createTaskEditForm($task);
        $taskForm->handleRequest($request);

        if ($taskForm->isSubmitted() && !$this->resourceAccessChecker->canEditTask($user, $task)) {
            throw $this->createAccessDeniedException();
        }

        if ($taskForm->isSubmitted() && $taskForm->isValid()) {
            /** @var TaskFormData $data */
            $data = $taskForm->getData();
            $this->taskManager->update($task, $data, $user);
            $this->addFlash('success', 'task_board.flash.task_updated');

            return $this->afterTaskWrite($request, $task, $user);
        }

        // Since 1.11: a fetch (side panel) gets the panel fragment, with the form errors (422) after an invalid save.
        if ($this->isPanelRequest($request)) {
            return $this->renderTaskPanel($request, $task, $user, $taskForm, $taskForm->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }

        return $this->render($this->templates['task'], $this->taskViewData($task, $user, $taskForm));
    }

    /**
     * Since 1.11: the task editing panel without layout (edit form, members, subtasks, links, priority) for hosts that
     * open a task in a side drawer. Its forms post to the usual routes and come back to `_target_path` (a local path,
     * default the task's board), or answer the refreshed fragment when they are sent with fetch.
     */
    public function taskPanel(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();

        return $this->renderTaskPanel($request, $this->findTask($taskId, $user), $user);
    }

    public function advanceTask(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_advance'));
        $task     = $this->findTask($taskId, $user, true);
        $action   = $request->request->getString('action');
        $columnId = $request->request->getString('columnId');

        $success = match (true) {
            $action === 'previous' => $this->taskManager->moveToPreviousColumn($task, $user),
            $action === 'next'     => $this->taskManager->moveToNextColumn($task, $user),
            $action === 'done'     => $this->taskManager->moveToDone($task, $user),
            $columnId !== ''       => $this->taskManager->moveToColumn($task, $columnId, $user),
            default                => false,
        };

        if ($success) {
            $flashKey = match ($action) {
                'previous' => 'task_board.flash.task_moved_previous',
                'next'     => 'task_board.flash.task_moved_next',
                'done'     => 'task_board.flash.task_moved_done',
                default    => 'task_board.flash.task_updated',
            };
            $this->addFlash('success', $flashKey);
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function moveTask(string $taskId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrf($request, 'task_board_task_move');
        $task     = $this->findTask($taskId, $user, true);
        $columnId = $request->request->getString('columnId');
        $position = $request->request->getInt('position', $task->getPosition());

        try {
            $this->taskManager->move($task, $columnId !== '' ? $columnId : null, $position, $user);
        } catch (ColumnNotFoundException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $task->getBoard()->getId()]);
    }

    public function attachLink(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);

        $form = $this->createForm(TaskLinkFormType::class, new TaskLinkFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskLinkFormData $data */
            $data = $form->getData();
            $this->linkAttacher->attach($task, $data, $user);
            $this->addFlash('success', 'task_board.flash.link_added');
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function updateLink(string $taskId, string $linkId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);

        $form = $this->createForm(TaskLinkFormType::class, new TaskLinkFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskLinkFormData $data */
            $data = $form->getData();
            if ($this->linkAttacher->update($task, $linkId, $data, $user)) {
                $this->addFlash('success', 'task_board.flash.link_updated');
            }
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function removeLink(string $taskId, string $linkId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_link_remove'));
        $task = $this->findTask($taskId, $user, true);

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        if ($this->linkAttacher->remove($task, $linkId, $user)) {
            $this->addFlash('success', 'task_board.flash.link_removed');
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function addMember(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);

        $form = $this->createMemberForm($task->getBoard());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskMemberFormData $data */
            $data = $form->getData();
            try {
                $this->memberAssigner->assign($task, $data, $user);
                $this->addFlash('success', 'task_board.flash.member_added');
            } catch (UnassignableUserException) {
                $this->addFlash('error', 'task_board.flash.member_not_assignable');
            }
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function removeMember(string $taskId, string $memberId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_member_remove_' . $memberId));
        $task = $this->findTask($taskId, $user, true);

        if ($this->memberAssigner->unassign($task, $memberId, $user)) {
            $this->addFlash('success', 'task_board.flash.member_removed');
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function updatePriority(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);
        $form = $this->createPriorityForm($task);
        $form->submit($request->request->all());
        if ($form->getErrors()->count() > 0) {
            // Root errors are CSRF failures; an unknown priority is a field error and is ignored as before.
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $priority = $form->isValid() ? TaskPriority::tryFrom((string) $form->get('priority')->getData()) : null;
        if (!$priority instanceof TaskPriority) {
            return $this->afterTaskWrite($request, $task, $user);
        }

        $this->taskManager->updatePriority($task, $priority, $user);
        $this->addFlash('success', 'task_board.flash.task_updated');

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function createSubtask(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);

        // The same compact form the task page renders (title and the hidden column), or the submission misses fields.
        $form = $this->createForm(TaskFormType::class, new TaskFormData(), [
            'column_choices' => $this->buildColumnChoices($task->getBoard()),
            'compact'        => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskFormData $data */
            $data = $form->getData();
            $this->taskManager->create($task->getBoard(), $data, $user, $task);
            $this->addFlash('success', 'task_board.flash.subtask_created');
        } elseif ($form->isSubmitted()) {
            $this->flashFormErrors($form);
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 1.15: adds a dependency between the task and another task of its board (CSRF, edit access); a dependency
     * on itself, towards another board, a duplicate or one closing a cycle is refused with a flash message.
     */
    public function addDependency(string $taskId, Request $request): Response
    {
        $user    = $this->requireUser();
        $task    = $this->findTask($taskId, $user, true);
        $manager = $this->dependencyManager ?? throw new NotFoundHttpException('Dependencies are not available.');

        $form = $this->createDependencyForm($task);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->afterTaskWrite($request, $task, $user);
        }

        $this->denyOnRootErrors($form);
        /** @var TaskDependencyFormData $data */
        $data  = $form->getData();
        $other = $form->isValid() && $data->taskId !== null ? $this->taskRepository->findById($data->taskId) : null;
        if (!$other instanceof Task) {
            $this->addFlash('error', 'task_board.flash.dependency_invalid');

            return $this->afterTaskWrite($request, $task, $user);
        }

        try {
            $manager->add($task, $other, $data->type, $user);
            $this->addFlash('success', 'task_board.flash.dependency_added');
        } catch (InvalidTaskDependencyException $exception) {
            $this->addFlash('error', 'task_board.flash.dependency_' . $exception->reason);
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 1.15: removes one of the task's dependencies (POST + CSRF, edit access).
     */
    public function removeDependency(string $taskId, string $dependencyId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_dependency_remove'));
        $task    = $this->findTask($taskId, $user, true);
        $manager = $this->dependencyManager ?? throw new NotFoundHttpException('Dependencies are not available.');

        if ($manager->remove($task, $dependencyId, $user)) {
            $this->addFlash('success', 'task_board.flash.dependency_removed');
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 1.15: logs time spent on the task (minutes, day, note; CSRF, edit access).
     */
    public function logTime(string $taskId, Request $request): Response
    {
        $user   = $this->requireUser();
        $task   = $this->findTask($taskId, $user, true);
        $logger = $this->timeLogger ?? throw new NotFoundHttpException('Time entries are not available.');

        $form = $this->createForm(TaskTimeEntryFormType::class, new TaskTimeEntryFormData(loggedAt: new DateTimeImmutable('today')));
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->denyOnRootErrors($form);
            /** @var TaskTimeEntryFormData $data */
            $data = $form->getData();
            if ($form->isValid() && $data->minutes !== null && $data->loggedAt !== null) {
                $logger->log($task, $user, $data->minutes, $data->loggedAt, $data->note);
                $this->addFlash('success', 'task_board.flash.time_logged');
            } else {
                $this->addFlash('error', 'task_board.flash.time_invalid');
            }
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 1.15: removes a manual time entry of the task (POST + CSRF, edit access).
     */
    public function removeTimeEntry(string $taskId, string $entryId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_time_remove'));
        $task   = $this->findTask($taskId, $user, true);
        $logger = $this->timeLogger ?? throw new NotFoundHttpException('Time entries are not available.');

        if ($logger->remove($task, $entryId, $user)) {
            $this->addFlash('success', 'task_board.flash.time_removed');
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * The view data of the task page and the task panel.
     *
     * @param FormInterface<TaskFormData> $taskForm
     * @param array<string, string> $routeExtra Query added to the write routes (the panel's `_target_path`)
     *
     * @return array<string, mixed>
     */
    private function taskViewData(Task $task, UserInterface $user, FormInterface $taskForm, array $routeExtra = []): array
    {
        $taskId      = $task->getId();
        $linkForm    = $this->createForm(TaskLinkFormType::class, new TaskLinkFormData());
        $memberForm  = $this->createMemberForm($task->getBoard());
        $subtaskForm = $this->createForm(TaskFormType::class, new TaskFormData(), [
            'column_choices' => $this->buildColumnChoices($task->getBoard()),
            'compact'        => true,
        ]);

        /** @var array<string, FormView> $memberRemoveForms */
        $memberRemoveForms = [];
        foreach ($task->getMembers() as $member) {
            $memberId                     = (string) $member->getId();
            $memberRemoveForms[$memberId] = $this->csrfActionForm(
                'task_board_task_member_remove_' . $memberId,
                $this->generateUrl($this->routes['task_member_remove']['name'], ['taskId' => $taskId, 'memberId' => $memberId] + $routeExtra),
            )->createView();
        }

        // The board's tasks in one query: the subtree (any depth) and its done / total without a query per node.
        $boardTasks      = $this->taskRepository->findByBoard($task->getBoard(), true);
        $taskTree        = TaskTree::fromTasks($boardTasks);
        $subtaskProgress = $taskTree->progress($task);
        $descendants     = $taskTree->descendantsOf($task);

        return [
            'task'              => $task,
            'taskForm'          => $taskForm,
            'linkForm'          => $linkForm,
            'memberForm'        => $memberForm,
            'subtaskForm'       => $subtaskForm,
            'columnNavigation'  => $this->taskManager->getColumnNavigation($task),
            'subtaskTotal'      => $subtaskProgress->total,
            'subtaskDone'       => $subtaskProgress->done,
            'subtaskProgress'   => $subtaskProgress,
            'taskTree'          => $taskTree,
            'canEditTask'       => $this->resourceAccessChecker->canEditTask($user, $task),
            'priorities'        => TaskPriority::cases(),
            'dashboardRoute'    => $this->dashboardRoute,
            'advanceForm'       => $this->csrfActionForm('task_board_task_advance')->createView(),
            'linkRemoveForm'    => $this->csrfActionForm('task_board_task_link_remove')->createView(),
            'priorityForm'      => $this->createPriorityForm($task, $routeExtra)->createView(),
            'memberRemoveForms' => $memberRemoveForms,
            'taskRouteExtra'    => $routeExtra,
            // Since 1.15: dependencies, time entries and the deviation (own, and the subtasks' sum).
            'dependencyForm'       => $this->dependencyManager instanceof TaskDependencyManager ? $this->createDependencyForm($task, $routeExtra, $boardTasks)->createView() : null,
            'dependencyRemoveForm' => $this->csrfActionForm('task_board_task_dependency_remove')->createView(),
            'timeEntryForm'        => $this->timeLogger instanceof TaskTimeLogger ? $this->createTimeEntryForm($task, $routeExtra)->createView() : null,
            'timeEntryRemoveForm'  => $this->csrfActionForm('task_board_task_time_remove')->createView(),
            'deviation'            => TaskDeviation::of($task),
            'subtaskDeviation'     => $descendants === [] ? null : TaskDeviation::sum($descendants),
        ];
    }

    /**
     * Since 1.15: the "Add dependency" form; its choices are the board's other tasks (title => id).
     *
     * @param array<string, string> $routeExtra
     * @param list<Task>|null $boardTasks The board's tasks when already loaded
     *
     * @return FormInterface<TaskDependencyFormData>
     */
    private function createDependencyForm(Task $task, array $routeExtra = [], ?array $boardTasks = null): FormInterface
    {
        $choices = [];
        foreach ($boardTasks ?? $this->taskRepository->findByBoard($task->getBoard(), true) as $candidate) {
            if ($candidate->getId() === $task->getId()) {
                continue;
            }

            $label  = $candidate->getTitle();
            $suffix = 2;
            while (array_key_exists($label, $choices)) {
                $label = sprintf('%s (%d)', $candidate->getTitle(), $suffix++);
            }

            $choices[$label] = $candidate->getId();
        }

        $route = $this->routes['task_dependency']['name'] ?? null;

        return $this->createForm(TaskDependencyFormType::class, new TaskDependencyFormData(), [
            'task_choices' => $choices,
            'action'       => is_string($route) ? $this->generateUrl($route, ['taskId' => $task->getId()] + $routeExtra) : '',
        ]);
    }

    /**
     * Since 1.15: the "Log time" form (today by default).
     *
     * @param array<string, string> $routeExtra
     *
     * @return FormInterface<TaskTimeEntryFormData>
     */
    private function createTimeEntryForm(Task $task, array $routeExtra = []): FormInterface
    {
        $route = $this->routes['task_time']['name'] ?? null;

        return $this->createForm(TaskTimeEntryFormType::class, new TaskTimeEntryFormData(loggedAt: new DateTimeImmutable('today')), [
            'action' => is_string($route) ? $this->generateUrl($route, ['taskId' => $task->getId()] + $routeExtra) : '',
        ]);
    }

    /**
     * Since 1.15: an error flash naming the first error of a refused form (field errors included).
     *
     * @param FormInterface<mixed> $form
     */
    private function flashFormErrors(FormInterface $form): void
    {
        $message = '';
        foreach ($form->getErrors(true) as $error) {
            $message = $error->getMessage();

            break;
        }

        $this->addFlash('error', $this->translator->trans('task_board.flash.task_invalid', ['%error%' => $message], 'NowoTaskBoardBundle'));
    }

    /**
     * A form error on the root (not on a field) is a CSRF failure: 403.
     *
     * @param FormInterface<mixed> $form
     */
    private function denyOnRootErrors(FormInterface $form): void
    {
        if ($form->getErrors()->count() > 0) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @return FormInterface<TaskFormData>
     */
    private function createTaskEditForm(Task $task): FormInterface
    {
        return $this->createForm(TaskFormType::class, new TaskFormData(
            title: $task->getTitle(),
            description: $task->getDescription(),
            priority: $task->getPriority(),
            columnId: $task->getColumn()?->getId(),
            estimatedMinutes: $task->getEstimatedMinutes(),
            dueAt: $task->getDueAt(),
            tags: $task->getTags(),
            color: $task->getColor(),
            startAt: $task->getStartAt(),
        ), [
            'column_choices' => $this->buildColumnChoices($task->getBoard()),
        ]);
    }

    /**
     * The task panel fragment (since 1.11); its forms carry `_target_path` (the request's, else the task's board).
     *
     * @param FormInterface<TaskFormData>|null $taskForm
     */
    private function renderTaskPanel(Request $request, Task $task, UserInterface $user, ?FormInterface $taskForm = null, int $status = Response::HTTP_OK): Response
    {
        $target = $this->safeTargetPath($request)
            ?? $this->generateUrl($this->routes['board']['name'], ['boardId' => $task->getBoard()->getId()]);

        $response = $this->render(
            $this->templates['task_panel'] ?? '@NowoTaskBoardBundle/manage/task_panel.html.twig',
            ['panel' => true, 'panelTargetPath' => $target] + $this->taskViewData($task, $user, $taskForm ?? $this->createTaskEditForm($task), ['_target_path' => $target]),
        );

        return $response->setStatusCode($status);
    }

    /**
     * Where a task write goes next (since 1.11): the refreshed panel fragment for a fetch, else the local
     * `_target_path` it carries, else the task page.
     */
    private function afterTaskWrite(Request $request, Task $task, UserInterface $user): Response
    {
        if ($this->isPanelRequest($request)) {
            return $this->renderTaskPanel($request, $task, $user);
        }

        $target = $this->safeTargetPath($request);

        return $target !== null
            ? $this->redirect($target)
            : $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $task->getId()]);
    }

    /**
     * A request sent by a side panel's fetch (`X-Requested-With: XMLHttpRequest` or a Turbo frame).
     */
    private function isPanelRequest(Request $request): bool
    {
        return $request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame');
    }

    /**
     * `_target_path` of the query or the body when it is a local path (no scheme, host or backslash): open redirects
     * are refused.
     */
    private function safeTargetPath(Request $request): ?string
    {
        $target = $request->query->get('_target_path', $request->request->get('_target_path'));
        if (!is_string($target) || !str_starts_with($target, '/') || preg_match('#^//|\\\\|[\x00-\x1f]#', $target) === 1) {
            return null;
        }

        return $target;
    }

    /**
     * Since 1.12: one compact task form per column (title and the hidden column) for the inline "+ New task" of the
     * board columns and the list groups; it posts to task_create with `inline=1` and comes back to this page.
     *
     * @param array<string, string> $columnChoices label => column id
     *
     * @return array<string, FormView> column id => form
     */
    private function inlineTaskForms(TaskBoard $board, array $columnChoices): array
    {
        $route = $this->routes['task_create']['name'] ?? null;
        if (!is_string($route)) {
            return [];
        }
        $action = $this->generateUrl($route, array_filter([
            'boardId'      => $board->getId(),
            'inline'       => 1,
            '_target_path' => $this->currentRequestUri(),
        ], static fn (mixed $value): bool => $value !== null));
        $forms = [];
        foreach ($columnChoices as $columnId) {
            $forms[$columnId] = $this->createForm(TaskFormType::class, new TaskFormData(columnId: $columnId), [
                'column_choices' => $columnChoices,
                'compact'        => true,
                'action'         => $action,
            ])->createView();
        }

        return $forms;
    }

    private function currentRequestUri(): ?string
    {
        return $this->currentRequest()?->getRequestUri();
    }

    private function currentRequest(): ?Request
    {
        /** @var RequestStack $requestStack */
        $requestStack = $this->container->get('request_stack');

        return $requestStack->getCurrentRequest();
    }

    /**
     * The add / edit column form; `board.column_icons` adds the icon field (since 1.11).
     *
     * @return FormInterface<BoardColumnFormData>
     */
    private function createColumnForm(BoardColumnFormData $data): FormInterface
    {
        return $this->createForm(BoardColumnFormType::class, $data, ['icon_choices' => $this->columnIcons]);
    }

    /**
     * The new board form; with column sets it offers them instead of the default columns (since 1.11).
     *
     * @return FormInterface<TaskBoardFormData>
     */
    private function createBoardForm(UserInterface $user, ?string $tenantKey): FormInterface
    {
        $sets = $this->columnSetManager?->available($user, null, $tenantKey) ?? [];

        return $this->createForm(TaskBoardFormType::class, new TaskBoardFormData(), [
            'column_set_choices' => $this->columnSetChoices($sets),
        ]);
    }

    /**
     * Column sets of the board page (editors): the sets, their delete forms (when allowed) and the two dialogs.
     *
     * @return array<string, mixed>
     */
    private function columnSetViewData(TaskBoard $board, UserInterface $user): array
    {
        if (!$this->columnSetManager instanceof ColumnSetManager) {
            return [];
        }

        $sets = $this->columnSetManager->available($user, $board);
        /** @var array<string, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($sets as $set) {
            if ($this->resourceAccessChecker->canDeleteColumnSet($user, $set)) {
                $deleteForms[$set->getId()] = $this->csrfActionForm(
                    'task_board_column_set_delete',
                    $this->generateUrl($this->routes['column_set_delete']['name'], ['boardId' => $board->getId(), 'setId' => $set->getId()]),
                )->createView();
            }
        }

        return [
            'columnSets'         => $sets,
            'columnSetForm'      => $this->createForm(ColumnSetFormType::class, new ColumnSetFormData())->createView(),
            'columnSetApplyForm' => $this->createForm(ColumnSetApplyFormType::class, new ColumnSetApplyFormData(), [
                'column_set_choices' => $this->columnSetChoices($sets),
            ])->createView(),
            'columnSetDeleteForms' => $deleteForms,
        ];
    }

    /**
     * Column set choices as name => id; duplicate names get a " (2)", " (3)"… suffix.
     *
     * @param list<ColumnSet> $sets
     *
     * @return array<string, string>
     */
    private function columnSetChoices(array $sets): array
    {
        $choices = [];
        foreach ($sets as $set) {
            $label  = $set->getName();
            $suffix = 2;
            while (array_key_exists($label, $choices)) {
                $label = sprintf('%s (%d)', $set->getName(), $suffix++);
            }

            $choices[$label] = $set->getId();
        }

        return $choices;
    }

    private function requireColumnSets(): ColumnSetManager
    {
        return $this->columnSetManager ?? throw new NotFoundHttpException('Column sets are not available.');
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * Flat CSRF-only form (`_token`) rendered with form_start/form_end — REQ-TWIG-005.
     *
     * @return FormInterface<mixed>
     */
    private function csrfActionForm(string $csrfTokenId, string $action = ''): FormInterface
    {
        return $this->formFactory()->createNamed('', CsrfActionType::class, null, [
            'csrf_token_id' => $csrfTokenId,
            'action'        => $action,
        ]);
    }

    /**
     * Member picker; with an assignee provider it offers only the users the provider allows on `$board`.
     *
     * @return FormInterface<TaskMemberFormData>
     */
    private function createMemberForm(TaskBoard $board): FormInterface
    {
        return $this->createForm(TaskMemberFormType::class, new TaskMemberFormData(), [
            'user_class'        => $this->userClass,
            'user_choice_label' => 'email',
            'user_choices'      => $this->memberAssigner->assignableUsers($board),
        ]);
    }

    /**
     * @param array<string, string> $routeExtra
     *
     * @return FormInterface<mixed>
     */
    private function createPriorityForm(Task $task, array $routeExtra = []): FormInterface
    {
        return $this->formFactory()->createNamed('', TaskPriorityFormType::class, ['priority' => $task->getPriority()->value], [
            'action' => $this->generateUrl($this->routes['task_priority']['name'], ['taskId' => $task->getId()] + $routeExtra),
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function denyUnlessValidCsrfForm(Request $request, FormInterface $form): void
    {
        // Root-less form: submit the POST body explicitly (handleRequest() skips a root-less form whose payload has no
        // child field, which is the case of the CSRF-only actions).
        $form->submit($request->request->all());
        if (!$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function formFactory(): FormFactoryInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->container->get('form.factory');

        return $factory;
    }

    private function denyUnlessValidCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Loads a board and enforces per-board access (creator, team member, manage role or listener grant); $forWrite
     * also rejects boards a listener marked read-only (403).
     */
    private function findBoard(string $boardId, UserInterface $user, bool $forWrite = false): TaskBoard
    {
        $board = $this->boardRepository->findById($boardId);
        // A board of another tenant is not found (tenancy).
        if (!$board instanceof TaskBoard || $this->tenantContext?->owns($board, $user) === false) {
            throw new NotFoundHttpException('Board not found.');
        }

        if (!$this->resourceAccessChecker->canViewBoard($user, $board)) {
            throw $this->createAccessDeniedException('Board access denied.');
        }

        if ($forWrite && !$this->resourceAccessChecker->canEditBoard($user, $board)) {
            throw $this->createAccessDeniedException('Board is read-only.');
        }

        return $board;
    }

    private function findColumn(TaskBoard $board, string $columnId): BoardColumn
    {
        foreach ($board->getColumns() as $column) {
            if ($column->getId() === $columnId) {
                return $column;
            }
        }

        throw new NotFoundHttpException('Column not found.');
    }

    /**
     * Loads a task and enforces per-task access; $forWrite also rejects tasks marked read-only by listeners.
     */
    private function findTask(string $taskId, UserInterface $user, bool $forWrite = false): Task
    {
        $task = $this->taskRepository->findById($taskId);
        // A task of another tenant's board is not found (tenancy).
        if (!$task instanceof Task || $this->tenantContext?->owns($task->getBoard(), $user) === false) {
            throw new NotFoundHttpException('Task not found.');
        }

        $allowed = $forWrite
            ? $this->resourceAccessChecker->canEditTask($user, $task)
            : $this->resourceAccessChecker->canViewTask($user, $task);
        if (!$allowed) {
            throw $this->createAccessDeniedException('Task access denied.');
        }

        return $task;
    }

    /**
     * Column choices as label => id. Duplicate column names get a " (2)", " (3)"… suffix so no column
     * is silently dropped from the select (ChoiceType keys are labels).
     *
     * @return array<string, string>
     */
    private function buildColumnChoices(TaskBoard $board): array
    {
        $choices = [];
        foreach ($board->getColumns() as $column) {
            $label  = $column->getName();
            $suffix = 2;
            while (array_key_exists($label, $choices)) {
                $label = sprintf('%s (%d)', $column->getName(), $suffix++);
            }

            $choices[$label] = $column->getId();
        }

        return $choices;
    }
}
