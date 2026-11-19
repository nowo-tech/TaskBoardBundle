<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use Countable;
use Nowo\TaskBoardBundle\Dto\TaskQuestionAnswerFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionWithdrawFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskQuestionAnswerFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionWithdrawFormType;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionConflictException;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use Nowo\TaskBoardBundle\Question\TaskQuestionView;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\Pagination;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function array_filter;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function preg_match;
use function str_replace;
use function str_starts_with;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.enabled`): the question writes of the task page and drawer (ask, answer,
 * withdraw, publish decision) and the *My open questions* page.
 *
 * Every write is a POST with its own CSRF token (the form's), loads the task through the tenant context and the view
 * access (else 404), finds the question on that task only (else 404), and lets {@see TaskQuestionManager} check the right
 * again (403 when refused). The answer comes back to `_target_path` (a local path), to the drawer (a drawer's fetch) or to
 * the task page. Nothing here changes the task's status.
 */
final class TaskQuestionController extends AbstractController
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskQuestionManager $manager,
        private readonly TaskQuestionView $view,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly int $perPage = 25,
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/tools/task-board/task/{taskId}/question', name: 'nowo_task_board_task_question_ask', methods: ['POST'])]
    public function ask(string $taskId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        $form          = $this->container->get('form.factory')->createNamed('task_question', TaskQuestionFormType::class, new TaskQuestionFormData(), [
            'recipients'    => $this->manager->candidates($user, $task),
            'csrf_token_id' => TaskQuestionView::csrfId('ask'),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            throw $this->createAccessDeniedException('Invalid form.');
        }
        $this->denyOnCsrfError($form->getErrors());
        if (!$form->isValid()) {
            $this->addFlash('error', 'task_board.question.flash.invalid');

            return $this->back($request, $task);
        }
        /** @var TaskQuestionFormData $data */
        $data = $form->getData();

        return $this->attempt($request, $task, function () use ($user, $task, $data): void {
            $question = $this->manager->ask($user, $task, $data->toInput());
            $this->log('task_question_ask', $task, $question);
        }, 'task_board.question.flash.asked');
    }

    #[Route('/tools/task-board/task/{taskId}/question/{questionId}/answer', name: 'nowo_task_board_task_question_answer', methods: ['POST'])]
    public function answer(string $taskId, string $questionId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        $question      = $this->question($task, $questionId);
        $form          = $this->container->get('form.factory')->createNamed('qa_' . str_replace('-', '', $question->getId()), TaskQuestionAnswerFormType::class, new TaskQuestionAnswerFormData(), ['question' => $question, 'csrf_token_id' => TaskQuestionView::csrfId('answer', $question->getId())]);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            throw $this->createAccessDeniedException('Invalid form.');
        }
        $this->denyOnCsrfError($form->getErrors());
        if (!$form->isValid()) {
            $this->addFlash('error', 'task_board.question.flash.invalid');

            return $this->back($request, $task);
        }
        /** @var TaskQuestionAnswerFormData $data */
        $data = $form->getData();

        return $this->attempt($request, $task, function () use ($user, $question, $data, $task): void {
            $this->manager->answer($user, $question, $data->toInput(), $data->versionNumber());
            $this->log('task_question_answer', $task, $question);
        }, 'task_board.question.flash.answered');
    }

    #[Route('/tools/task-board/task/{taskId}/question/{questionId}/withdraw', name: 'nowo_task_board_task_question_withdraw', methods: ['POST'])]
    public function withdraw(string $taskId, string $questionId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        $question      = $this->question($task, $questionId);
        $form          = $this->container->get('form.factory')->createNamed('qw_' . str_replace('-', '', $question->getId()), TaskQuestionWithdrawFormType::class, new TaskQuestionWithdrawFormData(), ['csrf_token_id' => TaskQuestionView::csrfId('withdraw', $question->getId())]);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            throw $this->createAccessDeniedException('Invalid form.');
        }
        $this->denyOnCsrfError($form->getErrors());
        if (!$form->isValid()) {
            $this->addFlash('error', 'task_board.question.flash.invalid');

            return $this->back($request, $task);
        }
        /** @var TaskQuestionWithdrawFormData $data */
        $data = $form->getData();

        return $this->attempt($request, $task, function () use ($user, $question, $data, $task): void {
            $this->manager->withdraw($user, $question, $data->reason, $data->versionNumber());
            $this->log('task_question_withdraw', $task, $question);
        }, 'task_board.question.flash.withdrawn');
    }

    #[Route('/tools/task-board/task/{taskId}/question/{questionId}/publish', name: 'nowo_task_board_task_question_publish', methods: ['POST'])]
    public function publish(string $taskId, string $questionId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        $question      = $this->question($task, $questionId);
        $form          = $this->container->get('form.factory')->createNamed('qp_' . str_replace('-', '', $question->getId()), CsrfActionType::class, null, ['csrf_token_id' => TaskQuestionView::csrfId('publish', $question->getId())]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->attempt($request, $task, function () use ($user, $question, $task): void {
            $this->manager->publish($user, $question);
            $this->log('task_question_publish', $task, $question);
        }, 'task_board.question.flash.published');
    }

    #[Route('/tools/task-board/questions', name: 'nowo_task_board_my_questions', methods: ['GET'])]
    public function mine(Request $request): Response
    {
        $user = $this->requireUser();
        // Since 2.23.1 (Beacon request): a tenant copy of this page whose tenant does not resolve for the user (unknown, or
        // one they are not a member of), or whose boards the tenant-aware access checker does not let them list, is 404.
        // 2.23.0 read it as "no tenant" and listed the questions of every board.
        if ($this->tenantContext?->isTenantRequest() === true) {
            $tenant = $this->tenantContext->require($user);
            if ($tenant instanceof TaskBoardTenant && $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface && !$this->accessChecker->canListBoards($user, $tenant->key)) {
                throw new NotFoundHttpException('Tenant not found.');
            }
        }
        $tab     = $request->query->get('tab') === TaskQuestionView::TAB_ASKED ? TaskQuestionView::TAB_ASKED : TaskQuestionView::TAB_TO_ANSWER;
        $rows    = $this->view->mine($tab);
        $perPage = (int) $request->query->get('per_page', (string) $this->perPage);
        $perPage = in_array($perPage, Pagination::ALLOWED_PER_PAGE, true) ? $perPage : $this->perPage;
        $page    = max(1, (int) $request->query->get('page', '1'));
        $total   = count($rows);
        $params  = $this->routeParams($request);
        $route   = $this->routes['my_questions']['name'] ?? 'nowo_task_board_my_questions';
        $twig    = $this->container->get('twig');
        $iconSet = $twig instanceof Environment ? $twig->getGlobals()['nowo_ui_kit_icon_set'] ?? null : null;

        $page = new ListPage(
            TaskBoardPages::header([
                'title' => TaskBoardPages::label('task_board.question.mine.title'),
                'intro' => TaskBoardPages::label('task_board.question.mine.intro'),
                'tabs'  => [
                    ['label' => TaskBoardPages::label('task_board.question.mine.to_answer'), 'href' => $this->generateUrl($route, $params), 'icon' => 'help', 'active' => $tab === TaskQuestionView::TAB_TO_ANSWER],
                    ['label' => TaskBoardPages::label('task_board.question.mine.asked'), 'href' => $this->generateUrl($route, $params + ['tab' => TaskQuestionView::TAB_ASKED]), 'icon' => 'user', 'active' => $tab === TaskQuestionView::TAB_ASKED],
                ],
            ], is_string($iconSet) ? $iconSet : 'tabler-icons'),
            [
                new Column('question', TaskBoardPages::label('task_board.question.mine.column.question'), required: true),
                new Column('task', TaskBoardPages::label('task_board.question.mine.column.task')),
                new Column('board', TaskBoardPages::label('task_board.question.mine.column.board'), priority: 2),
                new Column('due', TaskBoardPages::label('task_board.question.mine.column.due')),
                new Column('asked', TaskBoardPages::label('task_board.question.mine.column.asked'), priority: 3),
            ],
            array_slice($rows, ($page - 1) * $perPage, $perPage),
            pagination: $total > 0 ? Pagination::fromTotal($total, $page, $perPage, Pagination::ALLOWED_PER_PAGE, $this->perPage, $route, ($tab === TaskQuestionView::TAB_ASKED ? ['tab' => $tab] : []) + ['per_page' => (string) $perPage], $params) : null,
            empty: new EmptyState(TaskBoardPages::label('task_board.question.mine.empty.' . $tab)),
            caption: TaskBoardPages::label('task_board.question.mine.title'),
            bundle: TaskBoardPages::BUNDLE,
            layout: $twig instanceof Environment ? $this->pages->layout($twig) : null,
            attributes: ['data-testid' => 'nowo-task-my-questions'],
            captionVisible: false,
            rowAttributes: static fn (): array => ['data-testid' => 'nowo-task-my-question-row'],
        );

        return $this->render($this->templates['my_questions'], [
            'page'      => $page,
            'tab'       => $tab,
            'taskRoute' => $this->routes['task']['name'] ?? 'nowo_task_board_task',
        ]);
    }

    /**
     * @param callable(): void $write
     */
    private function attempt(Request $request, Task $task, callable $write, string $success): Response
    {
        try {
            $write();
            $this->addFlash('success', $success);
        } catch (TaskQuestionConflictException) {
            $this->addFlash('error', 'task_board.question.flash.conflict');
        } catch (TaskQuestionRefusedException $refused) {
            if ($refused->reason === TaskQuestionRefusedException::FORBIDDEN) {
                throw $this->createAccessDeniedException('Question access denied.');
            }
            $this->addFlash('error', 'task_board.question.flash.refused.' . $refused->reason);
        }

        return $this->back($request, $task);
    }

    /**
     * The form's own errors (not a field's): a missing or invalid CSRF token, extra fields.
     */
    private function denyOnCsrfError(Countable $errors): void
    {
        if (count($errors) > 0) {
            throw $this->createAccessDeniedException('Invalid form.');
        }
    }

    private function back(Request $request, Task $task): RedirectResponse
    {
        $target = $request->query->get('_target_path', $request->request->get('_target_path'));
        $target = is_string($target) && str_starts_with($target, '/') && preg_match('#^//|\\\\|[\x00-\x1f]#', $target) !== 1 ? $target : null;
        if ($request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame')) {
            // A drawer's fetch follows the redirect and gets the panel again.
            return $this->redirectToRoute($this->routes['task_panel']['name'] ?? 'nowo_task_board_task_panel', ['taskId' => $task->getId()] + ($target !== null ? ['_target_path' => $target] : []));
        }

        return $target !== null
            ? $this->redirect($target . '#nowo-task-questions')
            : $this->redirectToRoute($this->routes['task']['name'] ?? 'nowo_task_board_task', ['taskId' => $task->getId(), '_fragment' => 'nowo-task-questions']);
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @return array{0: UserInterface, 1: Task}
     */
    private function source(string $taskId): array
    {
        $user = $this->requireUser();
        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task || $task->getId() !== $taskId || $this->tenantContext?->owns($task->getBoard(), $user) === false || !$this->resourceAccessChecker->canViewTask($user, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }

        return [$user, $task];
    }

    private function question(Task $task, string $questionId): TaskQuestion
    {
        return $this->manager->findOnTask($task, $questionId) ?? throw new NotFoundHttpException('Question not found.');
    }

    /**
     * @return array<string, string>
     */
    private function routeParams(Request $request): array
    {
        $params = $request->attributes->get('_route_params');

        // The page's own route variables (a tenant slug), never the `_` ones.
        return array_filter(is_array($params) ? $params : [], static fn (mixed $value, int|string $name): bool => is_string($name) && !str_starts_with($name, '_') && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    private function log(string $action, Task $task, TaskQuestion $question): void
    {
        $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => $action, 'task_id' => $task->getId(), 'question_id' => $question->getId()]);
    }
}
