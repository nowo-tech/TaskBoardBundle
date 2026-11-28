<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskScheduleException;
use Nowo\TaskBoardBundle\Exception\TaskLinkedToProviderException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Menu\TaskMenuBuilder;
use Nowo\TaskBoardBundle\Menu\TaskMenuOperation;
use Nowo\TaskBoardBundle\Menu\TaskTimerStart;
use Nowo\TaskBoardBundle\Menu\TaskTimerStarterInterface;
use Nowo\TaskBoardBundle\Provenance\Attribute\AsWriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Service\TaskBoardEraserInterface;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Tree\TaskTreeRefusedException;
use Nowo\UiKitBundle\Dialog\RemoteDialogTarget;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function is_object;
use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strtolower;

use const PHP_URL_HOST;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * Since 2.22.0 (context menus): the writes of the task menu of the board cards and list rows — Status, Priority, Due date,
 * Assign to me / Remove me, Start timer, Archive and Delete — through the services the rest of the UI uses
 * ({@see TaskManager}, with the ordered locking of 2.21, {@see TaskMemberAssigner}, {@see TaskBoardEraserInterface},
 * TimeTrack's timer). One POST route for the page's one CSRF-only form (REQ-TWIG-005): each entry is a submit button of that
 * form whose `action` is `<task id>|<operation>[:<value>]` ({@see TaskMenuOperation::parse()}), with the CSRF token
 * {@see TaskMenuOperation::CSRF_TOKEN_ID}, from the same origin only (`Sec-Fetch-Site`, else `Origin`).
 *
 * Every operation checks again what the menu checked before offering it — an entry the menu hides answers 403 when it is
 * posted anyway — and then whatever the service checks (transition policy, the host's limits, the assignee policy,
 * the time tracker). Afterwards it goes back to the local `_target_path`, else the task's board. Stateless.
 */
#[AsWriteChannel(WriteChannel::Web)]
final class TaskMenuController extends AbstractController
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly TaskBoardResourceAccessChecker $resourceAccessChecker,
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskManager $taskManager,
        private readonly TaskMemberAssigner $memberAssigner,
        private readonly TaskBoardEraserInterface $eraser,
        private readonly TaskMenuBuilder $menus,
        private readonly array $routes,
        private readonly ?TaskBoardTenantContext $tenantContext = null,
        private readonly ?TaskTimerStarterInterface $timer = null,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/tools/task-board/menu/task', name: 'nowo_task_board_task_menu', methods: ['POST'])]
    public function act(Request $request): RedirectResponse
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isSameOrigin($request)) {
            $this->refused('cross_origin');

            throw $this->createAccessDeniedException('Cross-origin request.');
        }
        if (!$this->isCsrfTokenValid(TaskMenuOperation::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->refused('csrf_invalid');

            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        [$taskId, $operation, $value] = TaskMenuOperation::parse($request->request->getString('action'));
        if ($taskId === '' || !in_array($operation, TaskMenuOperation::ALL, true)) {
            throw new BadRequestHttpException('Unknown task menu action.');
        }

        $task    = $this->findTask($taskId, $user);
        $boardId = $task->getBoard()->getId();
        $this->authorize($operation, $task, $user);
        $done = match ($operation) {
            TaskMenuOperation::STATUS      => $this->status($task, $value, $user),
            TaskMenuOperation::PRIORITY    => $this->priority($task, $value, $user),
            TaskMenuOperation::DUE         => $this->due($task, $value, $user),
            TaskMenuOperation::ASSIGN_ME   => $this->assignMe($task, $user),
            TaskMenuOperation::UNASSIGN_ME => $this->unassignMe($task, $user),
            TaskMenuOperation::TIMER       => $this->startTimer($task, $user),
            TaskMenuOperation::ARCHIVE     => $this->archive($task, $user),
            default                        => $this->delete($task, $user),
        };
        if ($done) {
            $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => 'task_menu_' . $operation, 'task_id' => $taskId, 'board_id' => $boardId]);
        }

        $target = $this->safeTargetPath($request);

        return $target !== null && !($operation === TaskMenuOperation::DELETE && $done && str_starts_with($target, $this->generateUrl($this->routes['task']['name'], ['taskId' => $taskId])))
            ? $this->redirect($target, Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId], Response::HTTP_SEE_OTHER);
    }

    /**
     * The task the user may see (another tenant's is not found, as everywhere in the UI); the operation's own right is
     * {@see self::authorize()}.
     */
    private function findTask(string $taskId, object $user): Task
    {
        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task || $this->tenantContext?->owns($task->getBoard(), $user) === false || !$this->resourceAccessChecker->canViewTask($user, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
    }

    /**
     * The right the menu asked before offering the entry, asked again: an entry posted without it is 403.
     *
     * @throws AccessDeniedException
     */
    private function authorize(string $operation, Task $task, object $user): void
    {
        $allowed = match ($operation) {
            TaskMenuOperation::TIMER  => $this->timer?->isAvailable() === true && !$task->isArchived() && $this->resourceAccessChecker->canTrackTime($user, $task),
            TaskMenuOperation::DELETE => $this->resourceAccessChecker->canManageBoard($user, $task->getBoard()),
            // Status, priority, due date, assignment and archive: edit rights on a task that is not archived.
            default => !$task->isArchived() && $this->resourceAccessChecker->canEditTask($user, $task),
        };
        if (!$allowed) {
            $this->refused('task_menu_' . $operation);

            throw $this->createAccessDeniedException('Task menu action denied.');
        }
    }

    private function status(Task $task, string $columnId, object $user): bool
    {
        try {
            if ($this->taskManager->moveToColumn($task, $columnId, $user)) {
                $this->addFlash('success', 'task_board.flash.task_updated');

                return true;
            }
            $this->addFlash('error', 'task_board.flash.task_move_refused');
        } catch (TaskReopenRefusedException|ColumnTransitionRefusedException|TaskTreeRefusedException $refused) {
            $this->addFlash('error', self::moveRefusal($refused));
        }

        return false;
    }

    /**
     * The flash of a status the move refused, as the board says it: the host's limit on reopening, the transition policy's
     * own message (else the bundle's), or — Beacon spec 239 — a task changed meanwhile (nothing was written).
     */
    public static function moveRefusal(TaskReopenRefusedException|ColumnTransitionRefusedException|TaskTreeRefusedException $refused): string
    {
        return match (true) {
            $refused instanceof TaskReopenRefusedException       => 'task_board.flash.task_reopen_refused',
            $refused instanceof ColumnTransitionRefusedException => $refused->decision->message !== '' ? $refused->decision->message : 'task_board.flash.task_move_refused',
            default                                              => 'task_board.flash.task_changed_meanwhile',
        };
    }

    private function priority(Task $task, string $value, object $user): bool
    {
        $priority = TaskPriority::tryFrom($value) ?? throw new BadRequestHttpException('Unknown priority.');
        $this->taskManager->updatePriority($task, $priority, $user);
        $this->addFlash('success', 'task_board.flash.task_updated');

        return true;
    }

    private function due(Task $task, string $choice, object $user): bool
    {
        $today = DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);
        $due   = match ($choice) {
            'today'     => $today,
            'tomorrow'  => $today->modify('+1 day'),
            'next_week' => $today->modify('+7 days'),
            'none'      => null,
            default     => throw new BadRequestHttpException('Unknown due date.'),
        };
        try {
            $this->taskManager->reschedule($task, $task->getStartAt(), $due, $user);
        } catch (InvalidTaskScheduleException) {
            $this->addFlash('error', 'task_board.menu.flash.due_before_start');

            return false;
        }
        $this->addFlash('success', 'task_board.flash.task_updated');

        return true;
    }

    private function assignMe(Task $task, object $user): bool
    {
        try {
            $this->memberAssigner->assign($task, new TaskMemberFormData($user, TaskMemberRole::Assignee), $user);
        } catch (UnassignableUserException) {
            $this->addFlash('error', 'task_board.flash.member_not_assignable');

            return false;
        }
        $this->addFlash('success', 'task_board.flash.member_added');

        return true;
    }

    private function unassignMe(Task $task, object $user): bool
    {
        $removed = false;
        foreach ($this->menus->memberIdsOf($task, $user) as $id) {
            $removed = $this->memberAssigner->unassign($task, $id, $user) || $removed;
        }
        if ($removed) {
            $this->addFlash('success', 'task_board.flash.member_removed');
        }

        return $removed;
    }

    private function startTimer(Task $task, object $user): bool
    {
        $result = $this->timer?->start($user, $task) ?? TaskTimerStart::Refused;
        $this->addFlash($result === TaskTimerStart::Started ? 'success' : 'warning', match ($result) {
            TaskTimerStart::Started        => 'task_board.menu.flash.timer_started',
            TaskTimerStart::AlreadyRunning => 'task_board.menu.flash.timer_already_running',
            TaskTimerStart::Refused        => 'task_board.menu.flash.timer_refused',
        });

        return $result === TaskTimerStart::Started;
    }

    /** An archived task never gets here ({@see self::authorize()}): archive() always archives it. */
    private function archive(Task $task, object $user): bool
    {
        $this->taskManager->archive($task, $user);
        $this->addFlash('success', 'task_board.menu.flash.archived');

        return true;
    }

    private function delete(Task $task, object $user): bool
    {
        try {
            $this->eraser->eraseTask($task, $user);
        } catch (TaskLinkedToProviderException) {
            $this->addFlash('error', 'task_board.menu.flash.delete_linked');

            return false;
        }
        $this->addFlash('success', 'task_board.menu.flash.deleted');

        return true;
    }

    /**
     * Same origin only: the browser's `Sec-Fetch-Site` when it sends one (`same-origin`), else the `Origin` header when
     * present (scheme, host and port of this request); a request with neither (an old browser, a test client) is let
     * through to the CSRF token, which it must still carry.
     */
    private function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (is_string($site) && $site !== '') {
            return strtolower($site) === 'same-origin';
        }
        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return true;
        }
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $host   = parse_url($origin, PHP_URL_HOST);
        $port   = parse_url($origin, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        return is_string($scheme) && is_string($host) && strtolower($scheme) === $request->getScheme()
            && strtolower($host) === strtolower($request->getHost()) && $port === $request->getPort();
    }

    /** `_target_path` of the query (the menu form's action) when it is a local path: open redirects are refused. */
    private function safeTargetPath(Request $request): ?string
    {
        $target = $request->query->get('_target_path');
        if (!is_string($target) || !str_starts_with($target, '/') || preg_match('#^//|\\\\|[\x00-\x1f]#', $target) === 1) {
            return null;
        }

        // TB-L1: the same rules as the dialog redirect (no `.` / `..` / `%2e` segments).
        return RemoteDialogTarget::tryPath($target, null);
    }

    private function refused(string $reason): void
    {
        $this->logger->warning('TaskBoard write refused: {reason}', ['bundle' => 'nowo_task_board', 'reason' => $reason, 'route' => 'task_menu']);
    }
}
