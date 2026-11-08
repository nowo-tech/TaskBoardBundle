<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskRecurrenceFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Exception\TaskCopyRefusedException;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskRecurrenceFormType;
use Nowo\TaskBoardBundle\Recurrence\Entity\TaskRecurrence;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceRule;
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
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_map;
use function is_object;
use function is_string;

/**
 * Since 2.3.0 (spec 215, `recurrence.enabled`): the Repeat page of a task (make recurring, edit) and its actions (pause,
 * resume, stop, skip a date, undo a skip). Who may: `canEditTask()`; a task the user cannot see answers 404, one they may
 * only read 403. Every write has its own CSRF token. Nothing here creates an occurrence: the host's schedule does.
 */
final class TaskRecurrenceController extends AbstractController
{
    public const ACTIONS = ['pause', 'resume', 'stop', 'skip', 'unskip'];

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskRecurrenceManager $manager,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function edit(string $taskId, Request $request): Response
    {
        [$user, $task] = $this->source($taskId);
        $series        = $this->manager->seriesOf($task);
        if (!$series instanceof TaskRecurrence && $this->manager->isSynced($task)) {
            throw $this->createAccessDeniedException('A synced task cannot repeat.');
        }
        $form = $this->createForm(TaskRecurrenceFormType::class, $series instanceof TaskRecurrence ? TaskRecurrenceFormData::fromSeries($series) : new TaskRecurrenceFormData(), [
            'action' => $this->generateUrl($this->routes['task_recurrence']['name'], ['taskId' => $task->getId()]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskRecurrenceFormData $data */
            $data = $form->getData();
            try {
                $rule = $data->rule(new DateTimeImmutable('today'));
                if ($series instanceof TaskRecurrence) {
                    $this->manager->update($series, $rule, $data->nextOnCompletion, $data->leadDays, $data->copySubtasks, $data->copyMembers);
                } else {
                    $series = $this->manager->create($task, $rule, $user, $data->nextOnCompletion, $data->leadDays, $data->copySubtasks, $data->copyMembers);
                }
                $this->log('task_recurrence_save', $task, $series);
                $this->addFlash('success', 'task_board.recurrence.flash.saved');

                return $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $task->getId()]);
            } catch (TaskCopyRefusedException $exception) {
                $this->addFlash('error', $this->translator->trans('task_board.copy.refusal.' . $exception->reason, $exception->params, 'NowoTaskBoardBundle'));
            }
        }

        $actions = ['skip' => [], 'unskip' => []];
        if ($series instanceof TaskRecurrence) {
            foreach (['pause', 'resume', 'stop'] as $action) {
                $actions[$action] = $this->actionForm($task, $action);
            }
            $after = $series->getMode() === TaskRecurrenceRule::MODE_AFTER_COMPLETION;
            foreach ($after ? [''] : array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $this->manager->upcoming($series, 5)) as $date) {
                $actions['skip'][$date] = $this->actionForm($task, 'skip', $date);
            }
            foreach ($after ? [''] : $series->getSkippedDates() as $date) {
                $actions['unskip'][$date] = $this->actionForm($task, 'unskip', $date);
            }
        }
        $view = $form->createView();
        $twig = $this->container->get('twig');

        return $this->render($this->templates['task_recurrence'], [
            'page' => new FormPage(
                new PageHeader(
                    TaskBoardPages::label($series instanceof TaskRecurrence ? 'task_board.recurrence.manage' : 'task_board.recurrence.make'),
                    $task->getTitle(),
                    [Action::route('custom', $this->routes['task']['name'], ['taskId' => $task->getId()], TaskBoardPages::label('task_board.copy.back'))],
                    [
                        new Crumb(TaskBoardTwigExtension::boardLabel($task->getBoard()), $this->generateUrl($this->routes['board']['name'], ['boardId' => $task->getBoard()->getId()])),
                        new Crumb($task->getTitle(), $this->generateUrl($this->routes['task']['name'], ['taskId' => $task->getId()])),
                    ],
                    breadcrumbsMode: 'own',
                ),
                $view,
                TaskBoardPages::label('task_board.action.save'),
                bundle: TaskBoardPages::BUNDLE,
                layout: $twig instanceof Environment ? $this->pages->layout($twig) : null,
            ),
            'task'           => $task,
            'series'         => $series,
            'upcoming'       => $series instanceof TaskRecurrence && $series->isActive() ? $this->manager->upcoming($series, 5) : [],
            'recurrenceForm' => $view,
            'actionForms'    => $actions,
        ]);
    }

    public function action(string $taskId, string $action, Request $request): RedirectResponse
    {
        [$user, $task] = $this->source($taskId);
        $series        = $this->manager->seriesOf($task);
        $form          = $this->container->get('form.factory')->createNamedBuilder('', CsrfActionType::class, null, ['csrf_token_id' => 'task_board_recurrence_' . $action])
            ->add('date', HiddenType::class, ['required' => false])
            ->getForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$series instanceof TaskRecurrence) {
            throw new NotFoundHttpException('The task does not repeat.');
        }
        $date = $form->get('date')->getData();
        $date = is_string($date) && $date !== '' ? $date : null;
        $done = match ($action) {
            'pause'  => $series->isActive() && $this->pauseManually($series),
            'resume' => $series->getStatus() === TaskRecurrence::STATUS_PAUSED && $this->resumeAs($series, $user),
            'stop'   => $this->stopNow($series),
            'skip'   => $this->manager->skip($series, $date),
            default  => $this->manager->unskip($series, $date),
        };
        if ($done) {
            $this->log('task_recurrence_' . $action, $task, $series);
        }
        $this->addFlash($done ? 'success' : 'error', 'task_board.recurrence.flash.' . ($done ? $action : 'not_done'));

        return $this->redirectToRoute($this->routes['task_recurrence']['name'], ['taskId' => $task->getId()]);
    }

    private function pauseManually(TaskRecurrence $series): bool
    {
        $this->manager->pause($series);

        return true;
    }

    private function resumeAs(TaskRecurrence $series, object $user): bool
    {
        $this->manager->resume($series, $user);

        return true;
    }

    private function stopNow(TaskRecurrence $series): bool
    {
        $this->manager->stop($series);

        return true;
    }

    private function actionForm(Task $task, string $action, string $date = ''): FormView
    {
        return $this->container->get('form.factory')->createNamedBuilder('', CsrfActionType::class, null, [
            'csrf_token_id' => 'task_board_recurrence_' . $action,
            'action'        => $this->generateUrl($this->routes['task_recurrence_action']['name'], ['taskId' => $task->getId(), 'action' => $action]),
        ])->add('date', HiddenType::class, ['required' => false, 'data' => $date])->getForm()->createView();
    }

    /**
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
        if (!$this->resourceAccessChecker->canEditTask($user, $task)) {
            throw $this->createAccessDeniedException('Task is read-only.');
        }

        return [$user, $task];
    }

    private function log(string $action, Task $task, TaskRecurrence $series): void
    {
        $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => $action, 'task_id' => $task->getId(), 'recurrence_id' => $series->getId()]);
    }
}
