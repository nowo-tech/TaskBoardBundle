<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use Nowo\TaskBoardBundle\Copy\TaskColumnRule;
use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Copy\TaskCopyOptions;
use Nowo\TaskBoardBundle\Copy\TaskCopyPreview;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskCopySelectFormType;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Move\TaskMovePlan;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\PageHeader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_filter;
use function array_values;
use function count;
use function is_object;
use function is_string;

/**
 * Since 2.3.0 (spec 217, `move.enabled`): the Move to another board and Duplicate pages of a task. The choices are a GET
 * form, so the preview under them follows with no JavaScript; the confirmation is a POST with its own CSRF token and the
 * preview's fingerprint: when the facts changed since, nothing is written and the new preview is shown with a notice.
 *
 * Access: the source task needs `canEditTask()` and its board `canEditBoard()`; the target must be one of
 * {@see TaskBoardTargetsInterface::targets()}, otherwise 404.
 */
final class TaskCopyController extends AbstractController
{
    public const MOVE_TOKEN = 'task_board_task_move_board';

    public const DUPLICATE_TOKEN = 'task_board_task_duplicate';

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskBoardTargetsInterface $targets,
        private readonly TaskMovePlanner $planner,
        private readonly TaskMover $mover,
        private readonly TaskCopier $copier,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function move(string $taskId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        $boards        = array_values(array_filter($this->targets->targets($task, $user), static fn (TaskBoard $board): bool => $board->getId() !== $task->getBoard()->getId()));
        $target        = $this->chosenTarget($request, $boards);
        $columns       = $target instanceof TaskBoard ? $this->columnFields($task, $target) : [];
        $select        = $this->selectForm($request, $boards, $target, $columns, false);
        $map           = [];
        foreach ($columns as $column) {
            $value = $select->has('c' . $column['id']) ? $select->get('c' . $column['id'])->getData() : null;
            if (is_string($value)) {
                $map[$column['id']] = $value;
            }
        }

        $plan    = null;
        $refusal = null;
        $notice  = null;
        if ($target instanceof TaskBoard) {
            try {
                $plan = $this->planner->plan($task, $target, $map);
            } catch (TaskCopyRefusedException $exception) {
                $refusal = $exception;
            }
        }
        $confirm = $plan instanceof TaskMovePlan ? $this->confirmForm(self::MOVE_TOKEN, $plan->fingerprint) : null;
        if ($confirm instanceof FormInterface && $plan instanceof TaskMovePlan && $target instanceof TaskBoard) {
            $confirm->handleRequest($request);
            if ($confirm->isSubmitted() && $confirm->isValid()) {
                try {
                    $this->mover->move($task, $target, $map, (string) $confirm->get('fingerprint')->getData(), $user);
                    $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => 'task_move_board', 'task_id' => $task->getId(), 'board_id' => $target->getId(), 'tasks' => count($plan->taskIds)]);
                    $this->addFlash('success', 'task_board.copy.flash.moved');

                    return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $task->getId()]);
                } catch (TaskCopyRefusedException $exception) {
                    [$refusal, $notice, $confirm] = $this->afterRefusal($exception, self::MOVE_TOKEN, $plan->fingerprint, $confirm);
                }
            } elseif ($confirm->isSubmitted()) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
        }

        return $this->page('task_move', 'task_board.copy.move_title', $task, [
            'selectForm'  => $select->createView(),
            'confirmForm' => $confirm?->createView(),
            'plan'        => $plan,
            'refusal'     => $refusal,
            'notice'      => $notice,
            'target'      => $target,
        ]);
    }

    public function duplicate(string $taskId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        // The task's own board first, always offered: a duplicate there needs nothing the source does not.
        $boards = [$task->getBoard(), ...array_filter($this->targets->targets($task, $user), static fn (TaskBoard $board): bool => $board->getId() !== $task->getBoard()->getId())];
        $target = $this->chosenTarget($request, $boards) ?? $task->getBoard();
        $select = $this->selectForm($request, $boards, $target, [], true);
        $flags  = [];
        foreach (['subtasks', 'dates', 'members'] as $name) {
            $flags[$name] = !$select->isSubmitted() || (bool) $select->get($name)->getData();
        }
        $options = new TaskCopyOptions(
            targetBoard: $target,
            subtasks: $flags['subtasks'],
            dates: $flags['dates'],
            members: $flags['members'],
            title: $this->translator->trans('task_board.copy.copy_title', ['%title%' => $task->getTitle()], 'NowoTaskBoardBundle'),
            historyContext: 'duplicate',
        );

        $preview = null;
        $refusal = null;
        $notice  = null;
        try {
            $preview = $this->copier->preview($task, $options);
        } catch (TaskCopyRefusedException $exception) {
            $refusal = $exception;
        }
        $confirm = $preview instanceof TaskCopyPreview ? $this->confirmForm(self::DUPLICATE_TOKEN, $preview->fingerprint) : null;
        if ($confirm instanceof FormInterface && $preview instanceof TaskCopyPreview) {
            $confirm->handleRequest($request);
            if ($confirm->isSubmitted() && $confirm->isValid()) {
                try {
                    $copy = $this->copier->copy($task, $options, $user, (string) $confirm->get('fingerprint')->getData());
                    $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => 'task_duplicate', 'task_id' => $task->getId(), 'copy_id' => $copy->task->getId(), 'tasks' => count($copy->tasks)]);
                    $this->addFlash('success', 'task_board.copy.flash.duplicated');

                    return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $copy->task->getId()]);
                } catch (TaskCopyRefusedException $exception) {
                    [$refusal, $notice, $confirm] = $this->afterRefusal($exception, self::DUPLICATE_TOKEN, $preview->fingerprint, $confirm);
                }
            } elseif ($confirm->isSubmitted()) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
        }

        return $this->page('task_duplicate', 'task_board.copy.duplicate_title', $task, [
            'selectForm'  => $select->createView(),
            'confirmForm' => $confirm?->createView(),
            'preview'     => $preview,
            'refusal'     => $refusal,
            'notice'      => $notice,
            'target'      => $target,
        ]);
    }

    /**
     * The user and the task, which they must be able to edit, on a board they may edit.
     *
     * @return array{0: object, 1: Task}
     */
    private function source(string $taskId): array
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }
        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task || $this->tenantContext?->owns($task->getBoard(), $user) === false || !$this->resourceAccessChecker->canViewTask($user, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }
        if (!$this->resourceAccessChecker->canEditTask($user, $task) || !$this->resourceAccessChecker->canEditBoard($user, $task->getBoard())) {
            throw $this->createAccessDeniedException('Task is read-only.');
        }

        return [$user, $task];
    }

    /**
     * The target named in the query (`copy[target]`), which must be offered; none named: null.
     *
     * @param list<TaskBoard> $boards
     */
    private function chosenTarget(Request $request, array $boards): ?TaskBoard
    {
        $query = $request->query->all('copy');
        $id    = $query['target'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }
        foreach ($boards as $board) {
            if ($board->getId() === $id) {
                return $board;
            }
        }

        throw new NotFoundHttpException('Board not found.');
    }

    /**
     * One select per source column used by the open tasks of the tree: the target's open columns, the rule's choice first.
     *
     * @return list<array{id: string, label: string, choices: array<string, string>, data: string|null}>
     */
    private function columnFields(Task $task, TaskBoard $target): array
    {
        $open   = TaskColumnRule::openColumns($target);
        $fields = [];
        foreach (TaskMovePlanner::subtree($task) as $node) {
            $column = $node->getColumn();
            if (!$column instanceof BoardColumn || $node->isCompleted() || isset($fields[$column->getId()])) {
                continue;
            }
            $fields[$column->getId()] = [
                'id'      => $column->getId(),
                'label'   => $this->translator->trans('task_board.copy.column_from', ['%column%' => $column->getName()], 'NowoTaskBoardBundle'),
                'choices' => $this->columnChoices($open),
                'data'    => TaskColumnRule::target($target, $column, false)?->getId(),
            ];
        }

        return array_values($fields);
    }

    /**
     * Label => id; a repeated name gets its position, so no choice hides another.
     *
     * @param list<BoardColumn> $columns
     *
     * @return array<string, string>
     */
    private function columnChoices(array $columns): array
    {
        $choices = [];
        foreach ($columns as $index => $column) {
            $label           = isset($choices[$column->getName()]) ? $column->getName() . ' (' . ($index + 1) . ')' : $column->getName();
            $choices[$label] = $column->getId();
        }

        return $choices;
    }

    /**
     * @param list<TaskBoard> $boards
     * @param list<array{id: string, label: string, choices: array<string, string>, data: string|null}> $columns
     *
     * @return FormInterface<mixed>
     */
    private function selectForm(Request $request, array $boards, ?TaskBoard $target, array $columns, bool $copyFields): FormInterface
    {
        $choices = [];
        foreach ($boards as $board) {
            $label                                                                          = TaskBoardTwigExtension::boardLabel($board);
            $choices[isset($choices[$label]) ? $label . ' · ' . $board->getSlug() : $label] = $board->getId();
        }
        $form = $this->createForm(TaskCopySelectFormType::class, null, [
            'board_choices'      => $choices,
            'target'             => $target?->getId(),
            'target_placeholder' => $copyFields ? null : 'task_board.copy.choose_board',
            'columns'            => $columns,
            'copy_fields'        => $copyFields,
        ]);
        // Read from the query whatever the method: the confirmation posts to this URL, with the same choices.
        $query = $request->query->all('copy');
        if ($query !== []) {
            $form->submit($query);
        }

        return $form;
    }

    /**
     * A refused confirmation: a stale one shows the current preview with a notice and a new confirmation; any other reason
     * is shown as the refusal.
     *
     * @param FormInterface<mixed> $confirm
     *
     * @return array{0: TaskCopyRefusedException|null, 1: string|null, 2: FormInterface<mixed>}
     */
    private function afterRefusal(TaskCopyRefusedException $exception, string $tokenId, string $fingerprint, FormInterface $confirm): array
    {
        if ($exception->reason === 'stale') {
            return [null, 'task_board.copy.refusal.stale', $this->confirmForm($tokenId, $fingerprint)];
        }

        return [$exception, null, $confirm];
    }

    /**
     * @return FormInterface<mixed>
     */
    private function confirmForm(string $tokenId, string $fingerprint): FormInterface
    {
        return $this->container->get('form.factory')->createNamedBuilder('', CsrfActionType::class, null, ['csrf_token_id' => $tokenId])
            ->add('fingerprint', HiddenType::class, ['data' => $fingerprint])
            ->getForm();
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function page(string $template, string $title, Task $task, array $variables): Response
    {
        $boardRoute = $this->routes['board']['name'];
        $twig       = $this->container->get('twig');

        return $this->render($this->templates[$template], [
            'page' => new FormPage(
                new PageHeader(
                    TaskBoardPages::label($title),
                    $task->getTitle(),
                    [Action::route('custom', $this->routes['task']['name'], ['taskId' => $task->getId()], TaskBoardPages::label('task_board.copy.back'))],
                    [
                        new Crumb(TaskBoardTwigExtension::boardLabel($task->getBoard()), $this->generateUrl($boardRoute, ['boardId' => $task->getBoard()->getId()])),
                        new Crumb($task->getTitle(), $this->generateUrl($this->routes['task']['name'], ['taskId' => $task->getId()])),
                    ],
                    breadcrumbsMode: 'own',
                ),
                null,
                bundle: TaskBoardPages::BUNDLE,
                layout: $twig instanceof Environment ? $this->pages->layout($twig) : null,
                formTag: false,
            ),
            'task' => $task,
        ] + $variables);
    }
}
