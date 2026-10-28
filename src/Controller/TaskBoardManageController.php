<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Form\TaskMemberFormType;
use Nowo\TaskBoardBundle\Form\TaskPriorityFormType;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_exists;
use function is_object;
use function is_string;
use function reset;
use function sprintf;

/**
 * Manage UI for task boards, kanban, list, and task detail.
 */
final class TaskBoardManageController extends AbstractController
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
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
            $boards = $this->resourceAccessChecker->filterBoards($user, $candidates);
        }

        return $this->render($this->templates['index'], [
            'boards'         => $boards,
            'boardForm'      => $this->createForm(TaskBoardFormType::class, new TaskBoardFormData()),
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

        $form = $this->createForm(TaskBoardFormType::class, new TaskBoardFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskBoardFormData $data */
            $data  = $form->getData();
            $board = $this->boardCreator->create($data, $user, $tenant?->key);

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $board->getId()]);
        }

        return $this->redirectToRoute($this->routes['index']['name']);
    }

    public function board(string $boardId): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);

        $columnForm    = $this->createForm(BoardColumnFormType::class, new BoardColumnFormData());
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
            'canEditBoard'   => $this->resourceAccessChecker->canEditBoard($user, $board),
            'columnForm'     => $columnForm->createView(),
            'editColumnForm' => $this->createForm(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'taskForm'       => $taskForm->createView(),
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    public function createColumn(string $boardId, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user, true);

        $form = $this->createForm(BoardColumnFormType::class, new BoardColumnFormData());
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

        $form = $this->createForm(BoardColumnFormType::class, new BoardColumnFormData(
            name: $column->getName(),
            color: $column->getColor(),
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

        $form = $this->createForm(TaskFormType::class, new TaskFormData(), [
            'column_choices' => $this->buildColumnChoices($board),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskFormData $data */
            $data = $form->getData();
            $this->taskManager->create($board, $data, $user);
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
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
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);
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
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    public function ganttView(string $boardId): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);
        // Completed tasks are drawn as finished bars (and keep their subtasks attached to the tree).
        $tasks    = $this->taskRepository->findByBoard($board, true);
        $timeline = $this->ganttBuilder->build($tasks);

        return $this->render($this->templates['gantt'], [
            'board'          => $board,
            'timeline'       => $timeline,
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    public function task(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user);

        $taskData = new TaskFormData(
            title: $task->getTitle(),
            description: $task->getDescription(),
            priority: $task->getPriority(),
            columnId: $task->getColumn()?->getId(),
            estimatedMinutes: $task->getEstimatedMinutes(),
            dueAt: $task->getDueAt(),
            tags: $task->getTags(),
        );

        $taskForm = $this->createForm(TaskFormType::class, $taskData, [
            'column_choices' => $this->buildColumnChoices($task->getBoard()),
        ]);
        $taskForm->handleRequest($request);

        if ($taskForm->isSubmitted() && !$this->resourceAccessChecker->canEditTask($user, $task)) {
            throw $this->createAccessDeniedException();
        }

        if ($taskForm->isSubmitted() && $taskForm->isValid()) {
            /** @var TaskFormData $data */
            $data = $taskForm->getData();
            $this->taskManager->update($task, $data, $user);
            $this->addFlash('success', 'task_board.flash.task_updated');

            return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
        }

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
                $this->generateUrl($this->routes['task_member_remove']['name'], ['taskId' => $taskId, 'memberId' => $memberId]),
            )->createView();
        }

        // The board's tasks in one query: the subtree (any depth) and its done / total without a query per node.
        $taskTree        = TaskTree::fromTasks($this->taskRepository->findByBoard($task->getBoard(), true));
        $subtaskProgress = $taskTree->progress($task);

        return $this->render($this->templates['task'], [
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
            'priorityForm'      => $this->createPriorityForm($task)->createView(),
            'memberRemoveForms' => $memberRemoveForms,
        ]);
    }

    public function advanceTask(string $taskId, Request $request): RedirectResponse
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

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
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

    public function attachLink(string $taskId, Request $request): RedirectResponse
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

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
    }

    public function updateLink(string $taskId, string $linkId, Request $request): RedirectResponse
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

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
    }

    public function removeLink(string $taskId, string $linkId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_link_remove'));
        $task = $this->findTask($taskId, $user, true);

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        if ($this->linkAttacher->remove($task, $linkId, $user)) {
            $this->addFlash('success', 'task_board.flash.link_removed');
        }

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
    }

    public function addMember(string $taskId, Request $request): RedirectResponse
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

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
    }

    public function removeMember(string $taskId, string $memberId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_member_remove_' . $memberId));
        $task = $this->findTask($taskId, $user, true);

        if ($this->memberAssigner->unassign($task, $memberId, $user)) {
            $this->addFlash('success', 'task_board.flash.member_removed');
        }

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
    }

    public function updatePriority(string $taskId, Request $request): RedirectResponse
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
            return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
        }

        $this->taskManager->updatePriority($task, $priority, $user);
        $this->addFlash('success', 'task_board.flash.task_updated');

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
    }

    public function createSubtask(string $taskId, Request $request): RedirectResponse
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
        }

        return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $taskId]);
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
     * @return FormInterface<mixed>
     */
    private function createPriorityForm(Task $task): FormInterface
    {
        return $this->formFactory()->createNamed('', TaskPriorityFormType::class, ['priority' => $task->getPriority()->value], [
            'action' => $this->generateUrl($this->routes['task_priority']['name'], ['taskId' => $task->getId()]),
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
