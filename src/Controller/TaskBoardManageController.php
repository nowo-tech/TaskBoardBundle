<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatars;
use Nowo\TaskBoardBundle\Board\BoardScope;
use Nowo\TaskBoardBundle\Board\BoardScopeProviderInterface;
use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarDayAnnotator;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use Nowo\TaskBoardBundle\Comment\MentionCandidate;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskCalendarFilter;
use Nowo\TaskBoardBundle\Dto\TaskCommentFormData;
use Nowo\TaskBoardBundle\Dto\TaskDependencyFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskMemberFormData;
use Nowo\TaskBoardBundle\Dto\TaskTimeEntryFormData;
use Nowo\TaskBoardBundle\Dto\TaskWindow;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\CalendarMode;
use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Event\ColumnDeleteCheckEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Event\TaskBoardQuotaCheckEvent;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Exception\InvalidTaskScheduleException;
use Nowo\TaskBoardBundle\Exception\TaskReopenRefusedException;
use Nowo\TaskBoardBundle\Exception\TaskTimeBillableRefusedException;
use Nowo\TaskBoardBundle\Exception\UnassignableUserException;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetApplyFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskCalendarFilterType;
use Nowo\TaskBoardBundle\Form\TaskCommentFormType;
use Nowo\TaskBoardBundle\Form\TaskDependencyFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Form\TaskMemberFormType;
use Nowo\TaskBoardBundle\Form\TaskPriorityFormType;
use Nowo\TaskBoardBundle\Form\TaskScheduleFormType;
use Nowo\TaskBoardBundle\Form\TaskTemplatePickerType;
use Nowo\TaskBoardBundle\Form\TaskTimeEntryFormType;
use Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportOptions;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\TaskImportOrchestrator;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Member\MemberBadges;
use Nowo\TaskBoardBundle\Number\TaskNumberLedger;
use Nowo\TaskBoardBundle\Number\TaskNumberScope;
use Nowo\TaskBoardBundle\Repository\ArchiveAwareTaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\BoundedTaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskCalendarRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TenantAwareTaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Service\BoardColumnManager;
use Nowo\TaskBoardBundle\Service\ColumnSetManager;
use Nowo\TaskBoardBundle\Service\NullTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Service\TaskBoardQuota;
use Nowo\TaskBoardBundle\Service\TaskCalendarBuilder;
use Nowo\TaskBoardBundle\Service\TaskCommentManager;
use Nowo\TaskBoardBundle\Service\TaskCommentPolicy;
use Nowo\TaskBoardBundle\Service\TaskDependencyManager;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskLinkAttacher;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskMemberAssigner;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableDefaultProviderInterface;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableService;
use Nowo\TaskBoardBundle\Service\TaskTimeLogger;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Support\TaskNavigator;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Support\UserIdResolver;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Template\TaskTemplateManager;
use Nowo\TaskBoardBundle\Tenancy\FirstBoardProvisionerInterface;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingExceptionInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function http_build_query;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function mb_substr;
use function parse_url;
use function preg_match;
use function reset;
use function sprintf;
use function str_starts_with;
use function trim;

use const ARRAY_FILTER_USE_KEY;
use const PHP_QUERY_RFC3986;

/**
 * Manage UI for task boards, kanban, list, and task detail.
 */
final class TaskBoardManageController extends AbstractController
{
    /** Route variables never forwarded from the query by the default-view redirect (audit EX-L1). */
    private const DEFAULT_VIEW_DROPPED_KEYS = ['boardId', 'board', 'tenantSlug', 'taskId'];

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     * @param list<string> $columnIcons `board.column_icons` (since 1.11)
     * @param int $maxTasks `board.max_tasks` (since 1.21): the most tasks a board, list, Gantt or task page loads
     */
    // @phpstan-ignore constructor.unusedParameter ($syncLimits: deprecated, kept for positional callers, audit IA-I1)
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
        // Since 1.16: comments and @mentions; `comments.readers_can_comment` (default true).
        private readonly ?TaskCommentManager $commentManager = null,
        private readonly ?TaskCommentRepositoryInterface $commentRepository = null,
        private readonly ?TaskMentionCandidatesResolverInterface $mentionCandidates = null,
        private readonly bool $readersCanComment = true,
        // Since 1.21: `board.max_tasks` (REQ-PERF-001), the clock (REQ-DI-001) and the logger of the writes (REQ-OBS-001).
        private readonly int $maxTasks = Configuration::DEFAULT_MAX_TASKS,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
        // Since 1.23: the members' picture URLs, resolved once per page after the access checks.
        private readonly ?TaskMemberAvatars $avatars = null,
        // Since 1.24: the user's default board view (`board.default_view_resolver`) and the dialog mode header of an
        // invalid board create (`board.create_dialog_header`).
        private readonly ?TaskBoardDefaultViewResolverInterface $defaultViewResolver = null,
        private readonly ?string $createDialogHeader = null,
        // Since 1.27.0, deprecated since 2.2.0 and ignored (audit IA-I1): kept in its place so positional arguments do not
        // move. A restore asks `TaskManager`'s limit, which the container wires from the same `sync.limit_checker`.
        ?SyncLimitCheckerInterface $syncLimits = null,
        // Since 1.30.0 (spec 211).
        private readonly ?BoardScopeProviderInterface $scopeProvider = null,
        /** @var array<string, mixed> */
        private readonly array $boardFormOptions = [],
        // Since 2.0.0: the host's quota listeners, `tenancy.open_single_board` and `tenancy.first_board_provisioner`.
        private readonly TaskBoardQuota $quota = new TaskBoardQuota(),
        private readonly bool $openSingleBoard = false,
        private readonly ?FirstBoardProvisionerInterface $firstBoardProvisioner = null,
        // Since 2.0.0: the layout of the scaffolds (`web_ui.layout_template`).
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        // Since 2.1.0 (spec 214): `task_numbers.enabled` (null when off), and `calendar.enabled`.
        private readonly ?TaskNumberLedger $taskNumbers = null,
        private readonly ?TaskNumberScope $taskNumberScope = null,
        private readonly TaskCalendarBuilder $calendarBuilder = new TaskCalendarBuilder(),
        private readonly bool $calendarEnabled = false,
        // Since 2.6.0 (TB-18): `tenancy.tenant_paths[].open_single_board`, by the path's route name prefix.
        /** @var array<string, bool> */
        private readonly array $openSingleBoardPaths = [],
        // Since 2.9.0: `board.column_icon_form_type` / `_options` and the ids a column icon may name (BoardColumnFormType options).
        /** @var array{icon_form_type?: ?string, icon_form_options?: array<string, mixed>, icon_known_ids?: list<string>} */
        private readonly array $columnIconForm = [],
        // Since 2.10.0 (spec 216, FR-010): asks the host for the warnings of "Delete column" (COLUMN_DELETE_CHECK).
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        // Since 2.12.0 (Beacon D-20261111-12): the billable flag of manual entries (default and audited changes).
        private readonly ?TaskTimeBillableService $timeBillable = null,
        private readonly TaskTimeBillableDefaultProviderInterface $billableDefaults = new NullTaskTimeBillableDefaultProvider(),
        // Since 2.14.0 (Beacon spec 204): the host's working days, holidays and leave on the calendar, and
        // `calendar.skip_non_working_days` (a drag snaps to the next working day).
        private readonly ?CalendarDayAnnotator $dayAnnotator = null,
        private readonly bool $skipNonWorkingDays = false,
        // Since 2.15.0 (REQ-ARCH-001 rule 2): `database.user_reference`.
        private readonly string $userReference = 'entity',
        // Since 2.16.0 (Beacon spec 234, FR-061): `task_templates.enabled` (null when off).
        private readonly ?TaskTemplateManager $taskTemplates = null,
        // Since 2.19.0 (Beacon spec 234): the host's member badges, asked once for the task page.
        private readonly ?MemberBadges $memberBadges = null,
    ) {
    }

    public function index(): Response
    {
        $user   = $this->requireUser();
        $tenant = $this->tenantContext?->require($user);
        $this->denyUnlessCanListTenant($user, $tenant);
        if ($tenant instanceof TaskBoardTenant && $this->tenantContext?->isSingleBoard() === true) {
            $board = $this->boardRepository->findOneByTenant($tenant->key);
            // Since 1.24 (audit EX-I2): the first board is created only for a user the create check accepts; the
            // others see the (empty) index. Since 2.0.0 the quota event may refuse it too.
            if (!$board instanceof TaskBoard && $this->mayProvisionBoard($user, $tenant)) {
                $board = $this->boardCreator->create(new TaskBoardFormData(name: $tenant->label), $user, $tenant->key);
            }
            if ($board instanceof TaskBoard) {
                return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $board->getId()]);
            }
        }
        $opened = $this->openedBoard($user, $tenant);
        if ($opened instanceof TaskBoard) {
            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $opened->getId()]);
        }

        $scopes = $this->scopeProvider?->scopes($user, $tenant) ?? [];
        $form   = $this->createBoardForm($user, $tenant?->key, $scopes);

        // Since 1.24: `?new=1` opens the New board dialog on load (e.g. a host's "New board" link).
        return $this->renderIndex($user, $tenant, $form, $this->createBoardForm($user, $tenant?->key, $scopes), $this->currentRequest()?->query->has('new') === true);
    }

    /**
     * Creates a board. Since 1.24 the create check gets the tenant of the request
     * ({@see TenantAwareTaskBoardAccessCheckerInterface}), and an invalid form answers 422 with its values and
     * errors: the index page with the New board dialog open, or only the create form when the request carries the
     * `board.create_dialog_header` header (a host dialog). It used to redirect to the index and lose them.
     */
    public function createBoard(Request $request): Response
    {
        $user   = $this->requireUser();
        $tenant = $this->tenantContext?->require($user);
        $this->denyUnlessCanListTenant($user, $tenant);
        // Since 1.30.0 (spec 211): with a scope step the check runs on the tenant it resolves to, not on the URL's.
        $scopes = $this->scopeProvider?->scopes($user, $tenant) ?? [];
        if ($scopes === [] && !$this->canCreateBoard($user, $tenant?->key)) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createBoardForm($user, $tenant?->key, $scopes);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var TaskBoardFormData $data */
            $data = $form->getData();
            // Since 1.30.0 (spec 211): the scope step decides the tenant; a refusal is one form error, whatever the reason.
            $target = $scopes === [] ? $tenant : $this->scopedTenant($user, $form, $data);
            // Since 2.0.0: the host's quota may refuse the board.
            $refusal = $scopes === [] || $target instanceof TaskBoardTenant ? $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, $target?->key, null, 1, $user) : null;
            if ($refusal !== null) {
                if (!$this->isDialogRequest($request)) {
                    $this->addFlash('error', $refusal);

                    return $this->redirectToRoute($this->routes['index']['name']);
                }
                $form->addError(new FormError($refusal));
            } elseif ($scopes === [] || $target instanceof TaskBoardTenant) {
                // Since 1.11: the columns of a column set the user may apply (another scope's set is ignored).
                $set   = $data->columnSetId !== null ? $this->columnSetManager?->find($data->columnSetId, $user, null, $target?->key) : null;
                $board = $this->boardCreator->create($data, $user, $target?->key, $set);
                $this->logWrite('board_create', ['board_id' => $board->getId(), 'column_set_id' => $set?->getId()]);

                return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $board->getId()] + ($scopes !== [] && $target instanceof TaskBoardTenant && $this->tenantContext?->isEnabled() === true ? ['tenantSlug' => $target->slug] : []));
            }
        }

        if (!$form->isSubmitted()) {
            return $this->redirectToRoute($this->routes['index']['name']);
        }

        if ($this->isDialogRequest($request)) {
            return $this->unstorable($this->render('@NowoTaskBoardBundle/manage/_board_create_form.html.twig', [
                'form'      => $form->createView(),
                'in_dialog' => true,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY)));
        }

        // The <noscript> copy of the form shows the same values and errors.
        $fallback = $this->createBoardForm($user, $tenant?->key, $scopes);
        $fallback->handleRequest($request);
        $this->addFlash('error', 'task_board.flash.board_invalid');

        return $this->unstorable($this->renderIndex($user, $tenant, $form, $fallback, true, Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Audit EX-I1: a 422 answer holds the user's submitted values — `no-store, private` (kept by the session
     * listener), and `Vary` on the dialog header in both modes when one is configured.
     */
    private function unstorable(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        if ($this->createDialogHeader !== null && $this->createDialogHeader !== '') {
            $response->setVary($this->createDialogHeader, false);
        }

        return $response;
    }

    public function board(string $boardId): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);
        // Since 1.24: the user's default view (board.default_view_resolver) when no view was asked for.
        $defaultView = $this->defaultViewRedirect($user, $board);
        if ($defaultView instanceof RedirectResponse) {
            return $defaultView;
        }

        $canEditBoard = $this->resourceAccessChecker->canEditBoard($user, $board);
        // Since 2.5.0 (TB-13): the column controls (Add column, the pencil, the grip, the column sets) follow it.
        $canManageColumns = $canEditBoard && $this->resourceAccessChecker->canManageColumns($user, $board);
        $columnForm       = $this->createColumnForm(new BoardColumnFormData());
        $columnChoices    = $this->buildColumnChoices($board);

        $taskData = new TaskFormData(
            // Choices are label => id: the default column is the first *value* (id), not the label.
            columnId: $columnChoices === [] ? null : reset($columnChoices),
        );
        // Since 2.16.0 (FR-061): the Template picker; a chosen template pre-fills the form and opens the dialog.
        $templateView = $canEditBoard ? $this->templatePicker($user, $board, $taskData) : [];
        $taskForm     = $this->createForm(TaskFormType::class, $taskData, ['column_choices' => $columnChoices]);

        [$allTasks, $taskWindow] = $this->boardTasks($board);
        $taskTree                = TaskTree::fromTasks($allTasks);
        $cards                   = $this->topLevelOnly ? $taskTree->roots() : $allTasks;
        $this->primeAvatars($cards);

        return $this->render($this->templates['board'], [
            'board' => $board,
            // board.top_level_only: subtasks are counted on their parent card instead of having one of their own.
            'tasks'            => $cards,
            'taskTree'         => $taskTree,
            'canEditBoard'     => $canEditBoard,
            'canManageColumns' => $canManageColumns,
            'columnForm'       => $columnForm->createView(),
            'editColumnForm'   => $this->createColumnForm(new BoardColumnFormData())->createView(),
            'taskForm'         => $taskForm->createView(),
            'dashboardRoute'   => $this->dashboardRoute,
            // Since 1.11: column icons, column delete, column sets and the task panel URL of the cards.
            'columnIcons'      => $this->columnIcons,
            'columnDeleteForm' => $canManageColumns ? $this->csrfActionForm('task_board_column_delete')->createView() : null,
            // Since 2.10.0 (spec 216): the host's warnings per column id, shown by the delete dialog.
            'columnDeleteWarnings' => $canManageColumns ? $this->columnDeleteWarnings($board, $user) : [],
            'panelTargetPath'      => $this->currentRequestUri(),
            'inlineTaskForms'      => $canEditBoard ? $this->inlineTaskForms($board, $columnChoices) : [],
            // Since 1.16: comments per task (one query) for the card counters.
            'commentCounts' => $this->commentCounts($allTasks),
            // Since 1.21: "Showing N of M tasks" when the board has more than board.max_tasks.
            'taskWindow' => $taskWindow,
        ] + $templateView + ($canManageColumns ? $this->columnSetViewData($board, $user) : []));
    }

    /**
     * Since 2.16.0 (Beacon spec 234, FR-061): the add-task dialog's Template picker (`taskTemplatePicker`) when the board
     * offers the user templates. `?taskTemplate=<id>` pre-fills `$data` from a template the board offers (any other id
     * is ignored, as if none were chosen) and opens the dialog (`openAddTask`); `?taskTitle=` keeps the title typed.
     *
     * @return array<string, mixed>
     */
    private function templatePicker(object $user, TaskBoard $board, TaskFormData $data): array
    {
        if (!$this->taskTemplates instanceof TaskTemplateManager) {
            return [];
        }
        $choices = [];
        foreach ($this->taskTemplates->usableOn($user, $board) as $template) {
            $choices[$template->getName()] ??= $template->getId();
        }
        if ($choices === []) {
            return [];
        }
        $query    = $this->currentRequest()?->query;
        $asked    = $query?->get(TaskTemplatePickerType::FIELD_TEMPLATE);
        $template = is_string($asked) && $asked !== '' ? $this->taskTemplates->usable($user, $board, $asked) : null;
        if ($template !== null) {
            $this->taskTemplates->prefill($template, $board, $data);
            $title = $query?->get(TaskTemplatePickerType::FIELD_TITLE);
            if (is_string($title)) {
                $data->title = mb_substr(trim($title), 0, TaskFormType::TITLE_MAX);
            }
        }
        $picker = $this->container->get('form.factory')->createNamed('', TaskTemplatePickerType::class, null, [
            'action'    => $this->generateUrl($this->routes['board']['name'], ['boardId' => $board->getId()]),
            'templates' => $choices,
            'selected'  => $template?->getId(),
        ]);

        return ['taskTemplatePicker' => $picker->createView(), 'openAddTask' => $template !== null];
    }

    /**
     * Since 2.10.0 (spec 216, FR-010): the host's warning lines per column id ({@see TaskBoardEvents::COLUMN_DELETE_CHECK}),
     * asked once per page and only when a column can be deleted.
     *
     * @return array<string, list<string>>
     */
    private function columnDeleteWarnings(TaskBoard $board, UserInterface $user): array
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface || $board->getColumns()->count() < 2) {
            return [];
        }
        $ids = [];
        foreach ($board->getColumns() as $column) {
            $ids[] = $column->getId();
        }
        $check = new ColumnDeleteCheckEvent($board->getId(), $board->getTenantKey(), $ids, $user);
        $this->eventDispatcher->dispatch($check, TaskBoardEvents::COLUMN_DELETE_CHECK);

        return $check->getAllWarnings();
    }

    /**
     * Since 1.11: deletes a column; its tasks move to the first remaining column. The last column is kept.
     */
    public function deleteColumn(string $boardId, string $columnId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_column_delete'));
        $board  = $this->findBoardForColumns($boardId, $user);
        $column = $this->findColumn($board, $columnId);

        try {
            $deleted = $this->columnManager->delete($column, $user);
        } catch (TaskReopenRefusedException) {
            $this->logRefused('column_delete_limit');
            $this->addFlash('error', 'task_board.flash.column_delete_refused');

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
        } catch (ColumnTransitionRefusedException $refused) {
            // Since 2.16.0 (audit PC-M1): the column transition policy refused a task the deletion would move; nothing changed.
            $this->logRefused('column_delete_transition_policy');
            $this->addFlash('error', $this->transitionMessage($refused));

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
        }
        if ($deleted) {
            $this->logWrite('column_delete', ['board_id' => $boardId, 'column_id' => $columnId]);
        }
        $this->addFlash($deleted ? 'success' : 'error', $deleted ? 'task_board.flash.column_deleted' : 'task_board.flash.column_last');

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    /**
     * Since 1.11: saves the board's columns as a named column set.
     */
    public function createColumnSet(string $boardId, Request $request): RedirectResponse
    {
        $user    = $this->requireUser();
        $board   = $this->findBoardForColumns($boardId, $user);
        $manager = $this->requireColumnSets();

        $form = $this->createForm(ColumnSetFormType::class, new ColumnSetFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ColumnSetFormData $data */
            $data = $form->getData();
            $set  = $manager->saveFromBoard($board, $data->name, $user);
            $this->logWrite('column_set_create', ['board_id' => $boardId, 'column_set_id' => $set->getId()]);
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
        $board   = $this->findBoardForColumns($boardId, $user);
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
                $this->logWrite('column_set_apply', ['board_id' => $boardId, 'column_set_id' => $set->getId()]);
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
        $board   = $this->findBoardForColumns($boardId, $user);
        $manager = $this->requireColumnSets();
        $set     = $manager->find($setId, $user, $board) ?? throw new NotFoundHttpException('Column set not found.');

        if (!$this->resourceAccessChecker->canDeleteColumnSet($user, $set)) {
            throw $this->createAccessDeniedException('Column set access denied.');
        }

        $manager->delete($set);
        $this->logWrite('column_set_delete', ['board_id' => $boardId, 'column_set_id' => $setId]);
        $this->addFlash('success', 'task_board.flash.column_set_deleted');

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function createColumn(string $boardId, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $board = $this->findBoardForColumns($boardId, $user);

        $form = $this->createColumnForm(new BoardColumnFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BoardColumnFormData $data */
            $data = $form->getData();
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $column = $this->columnManager->add($board, $data, $user);
            $this->logWrite('column_create', ['board_id' => $boardId, 'column_id' => $column->getId()]);
            $this->addFlash('success', 'task_board.flash.column_added');
        } else {
            $this->flashColumnIconError($form);
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function updateColumn(string $boardId, string $columnId, Request $request): RedirectResponse
    {
        $user   = $this->requireUser();
        $board  = $this->findBoardForColumns($boardId, $user);
        $column = $this->findColumn($board, $columnId);

        $form = $this->createColumnForm(new BoardColumnFormData(
            name: $column->getName(),
            color: $column->getColor(),
            icon: $column->getIcon(),
            humansOnly: $column->isHumansOnly(),
        ), $column->getIcon());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var BoardColumnFormData $data */
            $data = $form->getData();
            // Since 2.9.0 (audit JM-L1): an edit that does not post the icon (the grid's <select> had no option for it) keeps it.
            if ($form->has('icon') && !array_key_exists('icon', $request->request->all($form->getName()))) {
                $data->icon = $column->getIcon();
            }
            $this->columnManager->update($column, $data, $user);
            $this->logWrite('column_update', ['board_id' => $boardId, 'column_id' => $columnId]);
            $this->addFlash('success', 'task_board.flash.column_updated');
        } else {
            $this->flashColumnIconError($form);
        }

        return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function reorderColumns(string $boardId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrf($request, 'task_board_column_reorder');
        $board = $this->findBoardForColumns($boardId, $user);

        $order = $request->request->all('columnOrder');
        foreach ($order as $columnId) {
            if (!is_string($columnId)) {
                throw new BadRequestHttpException('columnOrder must be a list of column ids.');
            }
        }

        if ($order !== []) {
            $this->columnManager->reorder($board, $order, $user);
            $this->logWrite('column_reorder', ['board_id' => $boardId, 'columns' => count($order)]);
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
            $data    = $form->getData();
            $refusal = $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, $board->getTenantKey(), $board, 1, $user);
            if ($refusal !== null) {
                $this->addFlash('error', $refusal);
            } else {
                try {
                    $created = $this->taskManager->create($board, $data, $user);
                    $this->logWrite('task_create', ['board_id' => $boardId, 'task_id' => $created->getId()]);
                } catch (ColumnTransitionRefusedException $refused) {
                    // Since 2.16.0 (spec 234): the host's column transition policy refused the column.
                    $this->logRefused('task_transition_policy');
                    $this->addFlash('error', $this->transitionMessage($refused));
                }
            }
        } elseif ($form->isSubmitted()) {
            // Since 1.15: a refused task (e.g. a title over 255 characters) says why instead of vanishing.
            $this->flashFormErrors($form);
        }

        // Since 2.1.0 (spec 214, FR-030): the calendar's add dialog comes back to the calendar.
        $target = $inline || $request->query->getBoolean('calendar') ? $this->safeTargetPath($request) : null;

        return $target !== null ? $this->redirect($target) : $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
    }

    public function importBoard(string $boardId, Request $request): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user, true);
        // Since 1.25: importing is its own decision (BoardAccessCheckEvent::ACTION_IMPORT_TASKS).
        if (!$this->resourceAccessChecker->canImportTasks($user, $board)) {
            throw $this->createAccessDeniedException('Task import denied.');
        }

        // Since 2.0.0 the form scaffold prints the form, so the form holds its own action.
        $form = $this->createForm(TaskImportFormType::class, new TaskImportFormData(), [
            'action' => $this->generateUrl($this->routes['board_import']['name'], ['boardId' => $boardId]),
        ]);
        $form->handleRequest($request);

        /** @var TaskImportFormData $data */
        $data = $form->getData();
        // The file field is NotBlank: a valid submission always carries the upload.
        $file = $form->isSubmitted() && $form->isValid() ? $data->file : null;
        if ($file !== null) {
            $result = $this->importOrchestrator->import(
                board: $board,
                source: $data->source,
                content: (string) file_get_contents($file->getPathname()),
                filename: (string) $file->getClientOriginalName(),
                actor: $user,
                // Since 1.22 every import is an idempotent upsert; the deprecated skipExisting is not passed on.
                options: new TaskImportOptions(createMissingColumns: $data->createMissingColumns),
            );

            foreach ($this->importErrors($result) as $error) {
                $this->addFlash('error', $error);
            }

            foreach ($result->warnings as $warning) {
                $this->addFlash('warning', $warning);
            }

            if (!$result->hasErrors()) {
                $this->addFlash('success', $this->translator->trans('task_board.flash.import_summary', [
                    '%created%'   => (string) $result->created,
                    '%updated%'   => (string) $result->updated,
                    '%unchanged%' => (string) $result->unchanged,
                    '%skipped%'   => (string) $result->skipped,
                    '%columns%'   => (string) $result->columnsCreated,
                ], 'NowoTaskBoardBundle'));
            }

            return $this->redirectToRoute($this->routes['board']['name'], ['boardId' => $boardId]);
        }

        $view = $form->createView();

        return $this->render($this->templates['import'], [
            'page' => new FormPage(
                new PageHeader(
                    TaskBoardPages::label('task_board.import.title'),
                    TaskBoardPages::label('task_board.import.subtitle'),
                    [
                        Action::route('custom', $this->routes['board']['name'], ['boardId' => $boardId], TaskBoardPages::label('task_board.view.back_board')),
                        ...($this->dashboardRoute !== null ? [Action::route('custom', $this->dashboardRoute, [], TaskBoardPages::label('task_board.action.back'))] : []),
                    ],
                    [new Crumb(TaskBoardTwigExtension::boardLabel($board), $this->generateUrl($this->routes['board']['name'], ['boardId' => $boardId]))],
                    breadcrumbsMode: 'own',
                ),
                $view,
                TaskBoardPages::label('task_board.import.form.submit'),
                bundle: TaskBoardPages::BUNDLE,
                layout: $this->pageLayout(),
            ),
            'board'          => $board,
            'importForm'     => $view,
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    /**
     * Since 1.18: imports a file of tasks as subtasks of a task — the top-level rows under the task, the rest under
     * their `parent` — with their Gantt relations (the bundle's own CSV / JSON documents them; third-party exports
     * work too). Needs edit rights on the task.
     */
    public function importIntoTask(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);
        if (!$this->resourceAccessChecker->canImportIntoTask($user, $task)) {
            throw $this->createAccessDeniedException('Task import denied.');
        }
        $form = $this->createSubtaskImportForm($task);
        $form->handleRequest($request);
        /** @var TaskImportFormData $data */
        $data = $form->getData();
        $file = $form->isSubmitted() && $form->isValid() ? $data->file : null;
        if ($file === null) {
            $this->addFlash('error', 'task_board.flash.import_file_required');

            return $this->afterTaskWrite($request, $task, $user);
        }

        $result = $this->importOrchestrator->import(
            board: $task->getBoard(),
            source: $data->source,
            content: (string) file_get_contents($file->getPathname()),
            filename: (string) $file->getClientOriginalName(),
            actor: $user,
            options: new TaskImportOptions(createMissingColumns: $data->createMissingColumns),
            parentTask: $task,
        );
        $this->logWrite('task_import', ['task_id' => $taskId, 'created' => $result->created, 'updated' => $result->updated, 'errors' => count($result->errors)]);
        foreach ([...$this->importErrors($result), ...$result->warnings] as $index => $message) {
            $this->addFlash($index < count($result->errors) ? 'error' : 'warning', $message);
        }
        if (!$result->hasErrors()) {
            $this->addFlash('success', $this->translator->trans('task_board.flash.subtask_import_summary', [
                '%created%'      => (string) $result->created,
                '%updated%'      => (string) $result->updated,
                '%unchanged%'    => (string) $result->unchanged,
                '%skipped%'      => (string) $result->skipped,
                '%dependencies%' => (string) $result->dependenciesCreated,
            ], 'NowoTaskBoardBundle'));
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    public function listView(string $boardId): Response
    {
        $user         = $this->requireUser();
        $board        = $this->findBoard($boardId, $user);
        $canEditBoard = $this->resourceAccessChecker->canEditBoard($user, $board);
        // Completed tasks (last column) stay visible so the column filter can show "Done" work.
        [$tasks, $taskWindow] = $this->boardTasks($board);

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
        $this->primeAvatars($tasks);
        // Since 1.23: the archived tasks of the board, each with its Restore form (editors).
        $archived     = $this->taskRepository instanceof ArchiveAwareTaskRepositoryInterface ? $this->taskRepository->findArchivedByBoard($board, $this->maxTasks) : [];
        $restoreForms = [];
        foreach ($canEditBoard ? $archived : [] as $archivedTask) {
            $restoreForms[$archivedTask->getId()] = $this->csrfActionForm('task_board_task_restore')->createView();
        }

        return $this->render($this->templates['list'], [
            'board'       => $board,
            'tasks'       => $tasks,
            'taskTree'    => TaskTree::fromTasks($tasks),
            'priorities'  => $priorityNames,
            'columnNames' => $columnNames,
            'filterForm'  => $this->formFactory()->createNamed('', TaskListFilterType::class, null, [
                'priorities'   => $priorityNames,
                'column_names' => $columnNames,
                'task_numbers' => $this->taskNumbers instanceof TaskNumberLedger,
            ])->createView(),
            'dashboardRoute'  => $this->dashboardRoute,
            'panelTargetPath' => $this->currentRequestUri(),
            // Since 1.12: the list groups the tasks by column, each group with its inline "+ New task".
            'canEditBoard'    => $canEditBoard,
            'inlineTaskForms' => $canEditBoard ? $this->inlineTaskForms($board, $this->buildColumnChoices($board)) : [],
            'commentCounts'   => $this->commentCounts($tasks),
            'taskWindow'      => $taskWindow,
            // Since 1.23: the archived tasks of the board (most recent first) and their Restore form.
            'archivedTasks' => $archived,
            'restoreForms'  => $restoreForms,
        ]);
    }

    /**
     * Since 1.23: makes an archived task active again (any archive reason; editors of the task only). A task a
     * provider sync archived is archived again by the next sync if the provider still does not list it.
     */
    public function restoreTask(string $taskId, Request $request): RedirectResponse
    {
        $user = $this->requireUser();
        $task = $this->findTask($taskId, $user, true);
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_restore'));
        // Since 1.29.0: a completed task is not an active task, so restoring it adds nothing to the limit.
        try {
            if ($this->taskManager->restore($task, $user)) {
                $this->logWrite('task_restore', ['task_id' => $taskId]);
                $this->addFlash('success', 'task_board.flash.task_restored');
            }
        } catch (TaskReopenRefusedException) {
            $this->addFlash('error', 'task_board.flash.task_restore_refused');
        }

        return $this->redirectToRoute($this->routes['list']['name'], ['boardId' => $task->getBoard()->getId()]);
    }

    public function ganttView(string $boardId): Response
    {
        $user  = $this->requireUser();
        $board = $this->findBoard($boardId, $user);
        // Completed tasks are drawn as finished bars (and keep their subtasks attached to the tree).
        [$tasks, $taskWindow] = $this->boardTasks($board);
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
            'taskWindow'      => $taskWindow,
        ]);
    }

    /**
     * Since 2.1.0 (spec 214, FR-018 to FR-022): the board's dated tasks by day (`calendar.enabled`). Everything it shows is in
     * the URL (`mode`, `date` and the filters), and the query reads only the tasks that overlap the visible days.
     */
    public function calendarView(string $boardId, Request $request): Response
    {
        $user       = $this->requireUser();
        $board      = $this->findBoard($boardId, $user);
        $repository = $this->taskRepository;
        // A host's own repository without the interface: a setup error the bundle's repository never hits.
        // @codeCoverageIgnoreStart
        if (!$repository instanceof TaskCalendarRepositoryInterface) {
            throw new LogicException('calendar.enabled needs a task repository that implements ' . TaskCalendarRepositoryInterface::class . '.');
        }
        // @codeCoverageIgnoreEnd

        // all(): a repeated parameter (`mode[]=`) is ignored here, where `get()` would answer 400.
        $query     = $request->query->all();
        $mode      = CalendarMode::fromQuery($query['mode'] ?? null);
        $date      = $this->calendarBuilder->day($query['date'] ?? null);
        $weekStart = $this->weekStartResolver?->resolve() ?? WeekStart::Monday;
        $columns   = $this->buildColumnChoices($board);
        // Choices are label => id: two people with one name both stay, told apart by id.
        $assignees = [];
        foreach ($repository->findCalendarAssignees($board) as $id => $person) {
            $label                                                                                = UserLabel::of($person);
            $assignees[array_key_exists($label, $assignees) ? $label . ' (' . $id . ')' : $label] = (string) $id;
        }

        $filter      = TaskCalendarFilter::fromQuery($query, array_values($assignees), array_values($columns));
        [$from, $to] = $this->calendarBuilder->range($mode, $date, $weekStart);
        $tasks       = $repository->findForCalendar($board, $from, $to, $filter, $this->maxTasks);
        $total       = count($tasks) < $this->maxTasks ? count($tasks) : $repository->countForCalendar($board, $from, $to, $filter);

        $editable = [];
        foreach ($tasks as $task) {
            $editable[$task->getId()] = $this->resourceAccessChecker->canEditTask($user, $task);
        }

        // Since 2.14.0: the host's day annotations of the visible days (none without calendar.day_annotation_provider).
        // Since 2.14.1 (audit NN-L1): a failing provider never breaks the calendar; it renders without annotations, as
        // in 2.13, and the failure is logged.
        try {
            $annotations = $this->dayAnnotator?->annotate(
                new CalendarViewer(UserIdResolver::getId($user), $board->getId(), $board->getTenantKey()),
                new CalendarDateRange($from->format('Y-m-d'), $to->modify('-1 day')->format('Y-m-d')),
                $tasks,
            );
        } catch (Throwable $exception) {
            $annotations = null;
            $this->logger->warning('TaskBoard calendar day annotations failed: {message}', ['bundle' => 'nowo_task_board', 'exception' => $exception, 'message' => $exception->getMessage()]);
        }

        $canEditBoard = $this->resourceAccessChecker->canEditBoard($user, $board);
        $filterForm   = $this->formFactory()->createNamed('', TaskCalendarFilterType::class, [
            'mode'      => $mode->value,
            'date'      => $date->format('Y-m-d'),
            'assignee'  => $filter->assignee,
            'priority'  => $filter->priority?->value,
            'column'    => $filter->columnId,
            'tag'       => $filter->tag,
            'completed' => $filter->completed,
            'subtasks'  => $filter->subtasks,
        ], ['assignees' => $assignees, 'columns' => $columns]);

        return $this->render($this->templates['calendar'], [
            'board'    => $board,
            'calendar' => $this->calendarBuilder->build($mode, $date, $weekStart, $tasks, $editable, $annotations),
            // Since 2.14.0: a drag snaps to the next working day of the viewer (with a provider only).
            'skipNonWorkingDays' => $this->skipNonWorkingDays && $annotations !== null,
            'calendarModes'      => CalendarMode::cases(),
            'calendarQuery'      => $filter->toQuery(),
            'calendarFiltered'   => $filter->isActive(),
            'filterForm'         => $filterForm->createView(),
            'undatedCount'       => $repository->countUndated($board, $filter),
            'dashboardRoute'     => $this->dashboardRoute,
            'panelTargetPath'    => $this->currentRequestUri(),
            'taskWindow'         => new TaskWindow(count($tasks), $total, $this->maxTasks),
            'canEditBoard'       => $canEditBoard,
            'taskForm'           => $canEditBoard ? $this->createForm(TaskFormType::class, new TaskFormData(columnId: $columns === [] ? null : reset($columns)), ['column_choices' => $columns])->createView() : null,
            'scheduleForm'       => in_array(true, $editable, true) ? $this->formFactory()->createNamed('', TaskScheduleFormType::class)->createView() : null,
        ]);
    }

    /**
     * Since 2.1.0 (spec 214, FR-026 to FR-028): changes a task's dates, from a drag, the Reschedule dialog or Undo. An empty date
     * clears it; a missing one is refused, so a partial request never clears a date. Out of reach: 404. Read-only: 403.
     */
    public function scheduleTask(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrf($request, TaskScheduleFormType::CSRF_TOKEN_ID);

        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task || $this->tenantContext?->owns($task->getBoard(), $user) === false || !$this->resourceAccessChecker->canViewTask($user, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }
        if (!$this->resourceAccessChecker->canEditTask($user, $task)) {
            throw $this->createAccessDeniedException('Task is read-only.');
        }
        if (!$request->request->has('start') || !$request->request->has('due')) {
            throw new BadRequestHttpException('Both dates are required.');
        }

        $error = null;

        try {
            $this->taskManager->reschedule($task, $this->scheduleDay($request->request->getString('start')), $this->scheduleDay($request->request->getString('due')), $user);
            $this->logWrite('task_schedule', ['task_id' => $taskId]);
        } catch (InvalidTaskScheduleException) {
            $error = 'task_board.form.task.start_after_due';
        } catch (InvalidArgumentException) {
            $error = 'task_board.calendar.failed';
        }

        $date    = ($task->getDueAt() ?? $task->getStartAt())?->format('Y-m-d');
        $message = $error ?? ($date !== null ? 'task_board.calendar.moved' : 'task_board.calendar.dates_cleared');
        $text    = $this->translator->trans($message, ['%date%' => (string) $date], 'NowoTaskBoardBundle');

        if ($this->isPanelRequest($request)) {
            return new JsonResponse(
                $error !== null ? ['message' => $text] : ['message' => $text, 'start' => $task->getStartAt()?->format('Y-m-d'), 'due' => $task->getDueAt()?->format('Y-m-d')],
                $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
            );
        }

        $this->addFlash($error !== null ? 'error' : 'success', $text);
        $target = $this->safeTargetPath($request);

        return $target !== null ? $this->redirect($target) : $this->redirectToRoute($this->routes['calendar']['name'], ['boardId' => $task->getBoard()->getId()]);
    }

    /**
     * Since 2.1.0 (spec 214, FR-010): a task by its number. A number names a task in a scope: the tenant of the URL, or `?board=`
     * where there is no tenant to tell (`scope: board`, or no tenancy). Unknown, retired and unreadable answer the same 404. An
     * alias of a task moved to another tenant goes to that tenant's page, for whoever may read it there.
     */
    public function taskByNumber(string $number, Request $request): RedirectResponse
    {
        $user  = $this->requireUser();
        $scope = $this->numberScopeOfRequest($request, $user);
        $task  = $scope !== null ? $this->taskNumbers?->find($scope, (int) $number) : null;
        if (!$task instanceof Task || !$this->resourceAccessChecker->canViewTask($user, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }

        $parameters = ['taskId' => $task->getId()];
        if ($this->tenantContext?->owns($task->getBoard(), $user) === false) {
            return $this->taskPageInItsTenant($task, $user) ?? throw new NotFoundHttpException('Task not found.');
        }

        return $this->redirectToRoute($this->routes['task']['name'], $parameters);
    }

    /**
     * Since 2.5.0 (TB-14): the old address of a task moved to another tenant. The task page of a task whose board is not
     * the URL's tenant goes to the task's page in its tenant now, for whoever may read it there; anyone else gets the
     * 404 they got before, so nothing tells them the task exists. Null: not such a task (the caller keeps its 404).
     */
    private function movedTaskRedirect(string $taskId, UserInterface $user): ?RedirectResponse
    {
        $task = $this->taskRepository->findById($taskId);
        if (!$task instanceof Task || $this->tenantContext?->owns($task->getBoard(), $user) !== false || !$this->resourceAccessChecker->canViewTask($user, $task)) {
            return null;
        }

        return $this->taskPageInItsTenant($task, $user);
    }

    /** The task page in the task's own tenant; null when the user has no tenant of that key. */
    private function taskPageInItsTenant(Task $task, UserInterface $user): ?RedirectResponse
    {
        $tenant = $this->tenantContext?->tenantOfKey((string) $task->getBoard()->getTenantKey(), $user);

        return $tenant instanceof TaskBoardTenant
            ? $this->redirectToRoute($this->routes['task']['name'], ['taskId' => $task->getId(), 'tenantSlug' => $tenant->slug])
            : null;
    }

    private function numberScopeOfRequest(Request $request, UserInterface $user): ?string
    {
        $tenant = $this->tenantContext?->current($user);
        if ($tenant instanceof TaskBoardTenant && $this->taskNumberScope?->isPerTenant() === true) {
            return $tenant->key;
        }

        $boardId = $request->query->all()['board'] ?? null;
        $board   = is_string($boardId) ? $this->boardRepository->findById($boardId) : null;
        if (!$board instanceof TaskBoard || $this->tenantContext?->owns($board, $user) === false || !$this->resourceAccessChecker->canViewBoard($user, $board)) {
            return null;
        }

        return $this->taskNumberScope?->keyOf($board);
    }

    /** A day of the schedule route: null when empty. */
    private function scheduleDay(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        // Audit HY-L1: only the years the calendar shows. Year 0000 also fails on PostgreSQL.
        if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $value || !$this->calendarBuilder->isInRange($day)) {
            throw new InvalidArgumentException('Not a day.');
        }

        return $day;
    }

    public function task(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        try {
            $task = $this->findTask($taskId, $user, detail: true);
        } catch (NotFoundHttpException $notFound) {
            // Since 2.5.0 (TB-14): a task moved to another tenant, opened at its old address (a page, not a panel fetch).
            // Audit IM-I12: HEAD answers as GET.
            $moved = in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true) && !$this->isPanelRequest($request) ? $this->movedTaskRedirect($taskId, $user) : null;

            return $moved ?? throw $notFound;
        }

        $taskForm = $this->createTaskEditForm($task);
        $taskForm->handleRequest($request);

        if ($taskForm->isSubmitted() && !$this->resourceAccessChecker->canEditTask($user, $task)) {
            throw $this->createAccessDeniedException();
        }

        if ($taskForm->isSubmitted() && $taskForm->isValid()) {
            /** @var TaskFormData $data */
            $data = $taskForm->getData();
            $this->keepTheColumnUnlessChanged($taskForm, $request, $data, $task);
            try {
                $this->taskManager->update($task, $data, $user);
                $this->logWrite('task_update', ['task_id' => $taskId]);
                $this->addFlash('success', 'task_board.flash.task_updated');
            } catch (ColumnTransitionRefusedException $refused) {
                // Since 2.16.0 (spec 234): the host's column transition policy refused the column; nothing is saved.
                $this->logRefused('task_transition_policy');
                $this->addFlash('error', $this->transitionMessage($refused));
            } catch (TaskReopenRefusedException) {
                // Since 2.2.1: a column change in the form is a move, so the host's limit refuses it as on the board; nothing is saved.
                $this->logRefused('task_reopen_limit');
                $this->addFlash('error', 'task_board.flash.task_reopen_refused');
            }

            return $this->afterTaskWrite($request, $task, $user);
        }

        // Since 1.11: a fetch (side panel) gets the panel fragment, with the form errors (422) after an invalid save.
        if ($this->isPanelRequest($request)) {
            return $this->renderTaskPanel($request, $task, $user, $taskForm, $taskForm->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }

        return $this->render($this->templates['task'], [
            'page' => new ShowPage(new PageHeader($task->getTitle()), bundle: TaskBoardPages::BUNDLE, layout: $this->pageLayout()),
        ] + $this->taskViewData($task, $user, $taskForm));
    }

    /**
     * Since 1.11: the task editing panel without layout (edit form, members, subtasks, links, priority) for hosts that
     * open a task in a side drawer. Its forms post to the usual routes and come back to `_target_path` (a local path,
     * default the task's board), or answer the refreshed fragment when they are sent with fetch.
     */
    public function taskPanel(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();

        return $this->renderTaskPanel($request, $this->findTask($taskId, $user, detail: true), $user);
    }

    public function advanceTask(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_advance'));
        $task     = $this->findTask($taskId, $user, true);
        $action   = $request->request->getString('action');
        $columnId = $request->request->getString('columnId');

        try {
            $success = match (true) {
                $action === 'previous' => $this->taskManager->moveToPreviousColumn($task, $user),
                $action === 'next'     => $this->taskManager->moveToNextColumn($task, $user),
                $action === 'done'     => $this->taskManager->moveToDone($task, $user),
                $columnId !== ''       => $this->taskManager->moveToColumn($task, $columnId, $user),
                default                => false,
            };
        } catch (TaskReopenRefusedException) {
            $this->logRefused('task_reopen_limit');
            $this->addFlash('error', 'task_board.flash.task_reopen_refused');
            $success = false;
        } catch (ColumnTransitionRefusedException $refused) {
            // Since 2.16.0 (spec 234).
            $this->logRefused('task_transition_policy');
            $this->addFlash('error', $this->transitionMessage($refused));
            $success = false;
        }

        if ($success) {
            $this->logWrite('task_advance', ['task_id' => $taskId, 'column_id' => $task->getColumn()?->getId()]);
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

    public function moveTask(string $taskId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrf($request, 'task_board_task_move');
        $task     = $this->findTask($taskId, $user, true);
        $columnId = $request->request->getString('columnId');
        $position = $request->request->getInt('position', $task->getPosition());

        try {
            $this->taskManager->move($task, $columnId !== '' ? $columnId : null, $position, $user);
            $this->logWrite('task_move', ['task_id' => $taskId, 'column_id' => $task->getColumn()?->getId(), 'position' => $position]);
        } catch (ColumnNotFoundException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        } catch (TaskReopenRefusedException $exception) {
            // Since 1.29.0 (audit HN-M1): the task stays. The board's drag reloads on 409, so the flash shows on that page.
            $this->logRefused('task_reopen_limit');
            $this->addFlash('error', 'task_board.flash.task_reopen_refused');
            if ($this->isPanelRequest($request)) {
                return new JsonResponse(['refused' => $exception->refusal->code, 'message' => $this->translator->trans('task_board.flash.task_reopen_refused', [], 'NowoTaskBoardBundle')], Response::HTTP_CONFLICT);
            }
        } catch (ColumnTransitionRefusedException $refused) {
            // Since 2.16.0 (spec 234): the task stays; the board's drag reloads on 409, so the flash shows on that page.
            $this->logRefused('task_transition_policy');
            $this->addFlash('error', $this->transitionMessage($refused));
            if ($this->isPanelRequest($request)) {
                return new JsonResponse(['refused' => $refused->decision->code, 'message' => $this->translator->trans($this->transitionMessage($refused), [], 'NowoTaskBoardBundle')], Response::HTTP_CONFLICT);
            }
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
            $this->logWrite('task_link', ['task_id' => $taskId]);
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
                $this->logWrite('task_link_update', ['task_id' => $taskId, 'link_id' => $linkId]);
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
            $this->logWrite('task_link_remove', ['task_id' => $taskId, 'link_id' => $linkId]);
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
                $this->logWrite('task_member', ['task_id' => $taskId]);
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
            $this->logWrite('task_member_remove', ['task_id' => $taskId, 'member_id' => $memberId]);
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
        $this->logWrite('task_priority', ['task_id' => $taskId, 'priority' => $priority->value]);
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
            $data    = $form->getData();
            $refusal = $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_TASK_CREATE, $task->getBoard()->getTenantKey(), $task->getBoard(), 1, $user);
            if ($refusal !== null) {
                $this->addFlash('error', $refusal);
            } else {
                try {
                    $subtask = $this->taskManager->create($task->getBoard(), $data, $user, $task);
                    $this->logWrite('task_subtask', ['task_id' => $taskId, 'subtask_id' => $subtask->getId()]);
                    $this->addFlash('success', 'task_board.flash.subtask_created');
                } catch (ColumnTransitionRefusedException $refused) {
                    $this->logRefused('task_transition_policy');
                    $this->addFlash('error', $this->transitionMessage($refused));
                }
            }
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
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $manager->add($task, $other, $data->type, $user);
            $this->logWrite('task_dependency', ['task_id' => $taskId, 'other_task_id' => $other->getId()]);
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

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        if ($manager->remove($task, $dependencyId, $user)) {
            $this->logWrite('task_dependency_remove', ['task_id' => $taskId, 'dependency_id' => $dependencyId]);
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

        $default = $this->billableDefault($task, $user);
        $form    = $this->createForm(TaskTimeEntryFormType::class, new TaskTimeEntryFormData(loggedAt: $this->today(), billable: $default ?? false));
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->denyOnRootErrors($form);
            /** @var TaskTimeEntryFormData $data */
            $data = $form->getData();
            if ($form->isValid() && $data->minutes !== null && $data->loggedAt !== null) {
                // Since 2.12.0: the switch; left off with no host default it stays unset, as before 2.12.
                $billable = $data->billable ? true : ($default === null ? null : false);
                // @igor-ignore - HTTP handler delegates to services; no controller worker state.
                $entry = $logger->log($task, $user, $data->minutes, $data->loggedAt, $data->note, $billable);
                $this->logWrite('task_time', ['task_id' => $taskId, 'entry_id' => $entry->getId()]);
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
        // Audit GWd-M2: the author of the entry or a manager of the board.
        foreach ($task->getTimeEntries() as $entry) {
            if ($entry->getId() === $entryId && !$this->resourceAccessChecker->canRemoveTimeEntry($user, $entry, $task)) {
                $this->logRefused('time_entry_not_own');

                throw $this->createAccessDeniedException('Only its author or a board manager may remove a time entry.');
            }
        }

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        if ($logger->remove($task, $entryId, $user)) {
            $this->logWrite('task_time_remove', ['task_id' => $taskId, 'entry_id' => $entryId]);
            $this->addFlash('success', 'task_board.flash.time_removed');
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 2.12.0 (Beacon D-20261111-12): marks a manual time entry billable (`billable=1`) or not (`billable=0`) — POST +
     * CSRF; its author or a board manager, not in a locked period, audited in the task's history
     * ({@see TaskTimeBillableService}).
     */
    public function setTimeEntryBillable(string $taskId, string $entryId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_time_billable'));
        $task    = $this->findTask($taskId, $user, true);
        $service = $this->timeBillable ?? throw new NotFoundHttpException('Time entries are not available.');
        $value   = $request->request->getString('billable');
        if (!in_array($value, ['0', '1'], true)) {
            throw new BadRequestHttpException('billable must be 0 or 1.');
        }

        try {
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $service->change($task, $entryId, $user, $value === '1');
        } catch (TaskTimeBillableRefusedException $e) {
            if ($e->errorCode === TaskTimeBillableRefusedException::NOT_FOUND) {
                throw new NotFoundHttpException('No such time entry.', $e);
            }
            if ($e->errorCode === TaskTimeBillableRefusedException::FORBIDDEN) {
                $this->logRefused('time_entry_not_own');

                throw $this->createAccessDeniedException('Only its author or a board manager may change a time entry.');
            }
            $this->addFlash('error', 'task_board.flash.time_billable_locked');

            return $this->afterTaskWrite($request, $task, $user);
        }
        $this->logWrite('task_time_billable', ['task_id' => $taskId, 'entry_id' => $entryId]);
        $this->addFlash('success', 'task_board.flash.time_billable_changed');

        return $this->afterTaskWrite($request, $task, $user);
    }

    /** Since 2.12.0: the host's default `billable` of a new manual entry on the task (null = none). */
    private function billableDefault(Task $task, object $user): ?bool
    {
        return $this->billableDefaults->defaultFor($user, $task->getId(), $task->getBoard()->getId());
    }

    /**
     * Since 1.16: comments on the task — readers too unless `comments.readers_can_comment` is false (CSRF, length,.
     *
     * @mentions of valid candidates only).
     */
    public function addComment(string $taskId, Request $request): Response
    {
        $user    = $this->requireUser();
        $task    = $this->findTask($taskId, $user);
        $manager = $this->commentManager ?? throw new NotFoundHttpException('Comments are not available.');
        if (!$this->canComment($user, $task)) {
            throw $this->createAccessDeniedException('Comments are read-only.');
        }

        $form = $this->createForm(TaskCommentFormType::class, new TaskCommentFormData());
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->denyOnRootErrors($form);
            /** @var TaskCommentFormData $data */
            $data = $form->getData();
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $this->saveComment($form->isValid(), static fn (): mixed => $manager->add($task, $user, $data->body, $data->mentionIds()), 'task_board.flash.comment_added', 'task_comment', ['task_id' => $taskId]);
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 1.16: the author edits their comment (others: 403); the mentions it adds are notified.
     */
    public function editComment(string $taskId, string $commentId, Request $request): Response
    {
        $user    = $this->requireUser();
        $task    = $this->findTask($taskId, $user);
        $manager = $this->commentManager ?? throw new NotFoundHttpException('Comments are not available.');
        $comment = $this->findComment($task, $commentId);
        if (!$this->commentPolicy()->mayEdit($user, $task, $comment)) {
            throw $this->createAccessDeniedException('Only the author edits a comment.');
        }

        $form = $this->createForm(TaskCommentFormType::class, new TaskCommentFormData());
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->denyOnRootErrors($form);
            /** @var TaskCommentFormData $data */
            $data = $form->getData();
            $this->saveComment($form->isValid(), static function () use ($manager, $comment, $user, $data): void {
                $manager->edit($comment, $user, $data->body, $data->mentionIds());
            }, 'task_board.flash.comment_updated', 'task_comment_edit', ['task_id' => $taskId, 'comment_id' => $commentId]);
        }

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * Since 1.16: the author, or an editor of the task, deletes a comment (POST + CSRF); its place stays in the stream.
     */
    public function deleteComment(string $taskId, string $commentId, Request $request): Response
    {
        $user = $this->requireUser();
        $this->denyUnlessValidCsrfForm($request, $this->csrfActionForm('task_board_task_comment_delete'));
        $task    = $this->findTask($taskId, $user);
        $manager = $this->commentManager ?? throw new NotFoundHttpException('Comments are not available.');
        $comment = $this->findComment($task, $commentId);
        if (!$this->canDeleteComment($user, $task, $comment)) {
            throw $this->createAccessDeniedException('Comment access denied.');
        }

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $manager->delete($comment, $user);
        $this->logWrite('task_comment_delete', ['task_id' => $taskId, 'comment_id' => $commentId]);
        $this->addFlash('success', 'task_board.flash.comment_deleted');

        return $this->afterTaskWrite($request, $task, $user);
    }

    /**
     * @param callable(): mixed $save
     * @param array<string, string> $ids logged with the action (REQ-OBS-001)
     */
    private function saveComment(bool $valid, callable $save, string $flash, string $action, array $ids): void
    {
        if (!$valid) {
            $this->addFlash('error', 'task_board.flash.comment_invalid');

            return;
        }

        try {
            $save();
            $this->logWrite($action, $ids);
            $this->addFlash('success', $flash);
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'task_board.flash.comment_invalid');
        }
    }

    private function findComment(Task $task, string $commentId): TaskComment
    {
        $comment = $this->commentRepository?->findById($commentId);
        if (!$comment instanceof TaskComment || $comment->getTask()->getId() !== $task->getId() || $comment->isDeleted()) {
            throw new NotFoundHttpException('Comment not found.');
        }

        return $comment;
    }

    private function commentPolicy(): TaskCommentPolicy
    {
        return new TaskCommentPolicy($this->resourceAccessChecker, $this->readersCanComment);
    }

    private function canComment(UserInterface $user, Task $task): bool
    {
        return $this->commentManager instanceof TaskCommentManager && $this->commentPolicy()->mayComment($user, $task);
    }

    private function canDeleteComment(UserInterface $user, Task $task, TaskComment $comment): bool
    {
        return $this->commentPolicy()->mayDelete($user, $task, $comment);
    }

    /**
     * Comments per task id for the board cards and the list rows (one query; none without comments).
     *
     * @param list<Task> $tasks
     *
     * @return array<string, int>
     */
    private function commentCounts(array $tasks): array
    {
        return $this->commentRepository?->countByTasks(array_map(static fn (Task $task): string => $task->getId(), $tasks)) ?? [];
    }

    /**
     * The comment data of the task page and panel: the comments (authors fetched with them), the box, the edit forms
     * of the viewer's comments, the delete form and the @mention candidates.
     *
     * @param array<string, string> $routeExtra
     *
     * @return array<string, mixed>
     */
    private function commentViewData(Task $task, UserInterface $user, array $routeExtra): array
    {
        if (!$this->commentManager instanceof TaskCommentManager) {
            return ['comments' => [], 'commentForm' => null];
        }

        $comments   = $this->commentRepository?->findByTask($task) ?? [];
        $canComment = $this->canComment($user, $task);
        $editForms  = [];
        $deletable  = [];
        foreach ($comments as $comment) {
            if ($comment->isDeleted()) {
                continue;
            }

            $deletable[$comment->getId()] = $this->canDeleteComment($user, $task, $comment);
            if ($canComment && TaskCommentManager::isAuthor($comment, $user)) {
                $editForms[$comment->getId()] = $this->createForm(TaskCommentFormType::class, new TaskCommentFormData($comment->getBody(), (string) json_encode($comment->getMentions())), [
                    'action' => $this->routeUrl('task_comment_edit', ['taskId' => $task->getId(), 'commentId' => $comment->getId()] + $routeExtra),
                ])->createView();
            }
        }

        $candidates = $canComment && $this->mentionCandidates instanceof TaskMentionCandidatesResolverInterface
            ? array_map(static fn (MentionCandidate $candidate): array => ['id' => $candidate->id, 'label' => $candidate->label], $this->mentionCandidates->candidates($task))
            : [];

        return [
            'comments'          => $comments,
            'commentForm'       => $canComment ? $this->createForm(TaskCommentFormType::class, new TaskCommentFormData(), ['action' => $this->routeUrl('task_comment', ['taskId' => $task->getId()] + $routeExtra)])->createView() : null,
            'commentEditForms'  => $editForms,
            'commentDeletable'  => $deletable,
            'commentDeleteForm' => $this->csrfActionForm('task_board_task_comment_delete')->createView(),
            'mentionCandidates' => $candidates,
            'viewerId'          => UserIdResolver::getId($user),
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function routeUrl(string $key, array $parameters): string
    {
        $route = $this->routes[$key]['name'] ?? null;

        return is_string($route) ? $this->generateUrl($route, $parameters) : '';
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
        [$boardTasks]    = $this->boardTasks($task->getBoard());
        $taskTree        = TaskTree::fromTasks($boardTasks);
        $subtaskProgress = $taskTree->progress($task);
        $descendants     = $taskTree->descendantsOf($task);

        return [
            'task'             => $task,
            'taskForm'         => $taskForm,
            'linkForm'         => $linkForm,
            'memberForm'       => $memberForm,
            'subtaskForm'      => $subtaskForm,
            'columnNavigation' => $this->taskManager->getColumnNavigation($task),
            // Since 1.20: previous / next in the board order (`?from=list`: the list's), and among the parent's subtasks.
            'taskNavigation'    => TaskNavigator::navigate($task, $boardTasks, $taskTree, $this->currentRequest()?->query->getString('from'), $this->topLevelOnly),
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
            // Since 1.18: import subtasks with their Gantt relations (editors only); since 1.26.2 only when the
            // ACTION_IMPORT_TASKS decision allows it, as importIntoTask() enforces.
            'subtaskImportForm'   => $this->resourceAccessChecker->canImportIntoTask($user, $task) ? $this->createSubtaskImportForm($task, $routeExtra)->createView() : null,
            'timeEntryForm'       => $this->timeLogger instanceof TaskTimeLogger ? $this->createTimeEntryForm($task, $user, $routeExtra)->createView() : null,
            'timeEntryRemoveForm' => $this->csrfActionForm('task_board_task_time_remove')->createView(),
            // Since 1.27.0: the entries this user may remove (their own, or all for a board manager).
            'removableTimeEntryIds' => array_values(array_map(static fn (TaskTimeEntry $entry): string => $entry->getId(), array_filter($task->getTimeEntries()->toArray(), fn (TaskTimeEntry $entry): bool => $this->resourceAccessChecker->canRemoveTimeEntry($user, $entry, $task)))),
            // Since 2.12.0: the entries whose billable flag this user may switch (same people; not a deleted user's entry).
            'billableTimeEntryIds'  => $this->timeBillable instanceof TaskTimeBillableService ? array_values(array_map(static fn (TaskTimeEntry $entry): string => $entry->getId(), array_filter($task->getTimeEntries()->toArray(), fn (TaskTimeEntry $entry): bool => $entry->getUser() !== null && $this->resourceAccessChecker->canEditTimeEntry($user, $entry, $task)))) : [],
            'timeEntryBillableForm' => $this->csrfActionForm('task_board_task_time_billable')->createView(),
            'deviation'             => TaskDeviation::of($task),
            'subtaskDeviation'      => $descendants === [] ? null : TaskDeviation::sum($descendants),
        ] + $this->withAvatars($task, $this->commentViewData($task, $user, $routeExtra));
    }

    /**
     * Since 1.23: one avatar call for the board or list — the first 3 members of each shown card / row (the chips
     * shown; the rest is "+N").
     *
     * @param iterable<Task> $tasks
     */
    private function primeAvatars(iterable $tasks): void
    {
        if (!$this->avatars instanceof TaskMemberAvatars) {
            return;
        }
        $users = [];
        foreach ($tasks as $task) {
            foreach (array_slice($task->getMembers()->toArray(), 0, 3) as $member) {
                $users[] = $member->getUser();
            }
        }
        $this->avatars->prime($users);
    }

    /**
     * Since 1.23: one avatar call for the task page / panel — its members, the comment authors and the history users.
     * Since 2.19.0 (Beacon spec 234): one member badge call for the same people.
     *
     * @param array<string, mixed> $commentData
     *
     * @return array<string, mixed>
     */
    private function withAvatars(Task $task, array $commentData): array
    {
        if ($this->avatars instanceof TaskMemberAvatars || $this->memberBadges instanceof MemberBadges) {
            $users = [];
            foreach ($task->getMembers() as $member) {
                $users[] = $member->getUser();
            }
            foreach (is_iterable($commentData['comments'] ?? null) ? $commentData['comments'] : [] as $comment) {
                if ($comment instanceof TaskComment && $comment->getAuthor() !== null) {
                    $users[] = $comment->getAuthor();
                }
            }
            foreach ($task->getChangeHistory() as $entry) {
                // Since 1.28.0: a row whose author's account is gone keeps only the name.
                if ($entry->getUser() !== null) {
                    $users[] = $entry->getUser();
                }
            }
            $this->avatars?->prime($users);
            $this->memberBadges?->prime($users);
        }

        return $commentData;
    }

    /**
     * Since 1.18: the import form of a task (the bundle's own CSV preselected).
     *
     * @param array<string, string> $routeExtra
     *
     * @return FormInterface<TaskImportFormData>
     */
    private function createSubtaskImportForm(Task $task, array $routeExtra = []): FormInterface
    {
        $route = $this->routes['task_import']['name'] ?? null;

        return $this->createForm(TaskImportFormType::class, new TaskImportFormData(source: TaskImportSource::TaskBoardCsv), [
            'action' => is_string($route) ? $this->generateUrl($route, ['taskId' => $task->getId()] + $routeExtra) : '',
        ]);
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
        foreach ($boardTasks ?? $this->boardTasks($task->getBoard())[0] as $candidate) {
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
    private function createTimeEntryForm(Task $task, object $user, array $routeExtra = []): FormInterface
    {
        $route = $this->routes['task_time']['name'] ?? null;

        return $this->createForm(TaskTimeEntryFormType::class, new TaskTimeEntryFormData(loggedAt: $this->today(), billable: $this->billableDefault($task, $user) ?? false), [
            'action' => is_string($route) ? $this->generateUrl($route, ['taskId' => $task->getId()] + $routeExtra) : '',
        ]);
    }

    /**
     * Since 2.9.0: a refused column icon (not one emoji, nor an offered icon) says so; other refusals stay silent as before.
     *
     * @param FormInterface<BoardColumnFormData> $form
     */
    private function flashColumnIconError(FormInterface $form): void
    {
        if ($form->isSubmitted() && $form->has('icon') && $form->get('icon')->getErrors()->count() > 0) {
            $this->addFlash('error', 'task_board.flash.column_icon_invalid');
        }
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
            $this->logRefused('csrf_invalid');

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
            'shown_column'   => true,
        ]);
    }

    /**
     * Since 2.2.1: the edit form changes the column only when the posted column differs from the one its page showed, so a
     * page left open while the task moved keeps it where it is now. A host template that does not post `columnIdShown`
     * (a form_end without render_rest) gets the posted column, as before.
     *
     * @param FormInterface<TaskFormData> $form
     */
    private function keepTheColumnUnlessChanged(FormInterface $form, Request $request, TaskFormData $data, Task $task): void
    {
        if (!$form->has('columnIdShown') || !array_key_exists('columnIdShown', $request->request->all($form->getName()))) {
            return;
        }
        if ((string) $form->get('columnIdShown')->getData() === (string) $data->columnId) {
            $data->columnId = $task->getColumn()?->getId();
        }
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
        $action = $this->generateUrl($this->routes['task_create']['name'], array_filter([
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
    /**
     * Since 2.16.0 (spec 234): the message of a refused move: the host policy's own, else the bundle's.
     */
    private function transitionMessage(ColumnTransitionRefusedException $refused): string
    {
        return $refused->decision->message !== '' ? $refused->decision->message : 'task_board.flash.task_move_refused';
    }

    /**
     * @return FormInterface<BoardColumnFormData>
     */
    private function createColumnForm(BoardColumnFormData $data, ?string $currentIcon = null): FormInterface
    {
        return $this->createForm(BoardColumnFormType::class, $data, [
            'icon_choices'         => $this->columnIcons,
            'icon_current'         => $currentIcon,
            'icon_invalid_message' => $this->translator->trans('task_board.form.column.icon_invalid', [], 'NowoTaskBoardBundle'),
        ] + $this->columnIconForm);
    }

    /**
     * The board index: the boards the user may list, whether they may create one here and the New board form (the
     * dialog's and the <noscript> copy).
     *
     * @param FormInterface<TaskBoardFormData> $form
     * @param FormInterface<TaskBoardFormData> $fallbackForm
     */
    private function renderIndex(UserInterface $user, ?TaskBoardTenant $tenant, FormInterface $form, FormInterface $fallbackForm, bool $openCreate, int $status = Response::HTTP_OK): Response
    {
        $boards = [];
        if ($this->canListBoards($user, $tenant?->key)) {
            $candidates = $tenant instanceof TaskBoardTenant
                ? $this->boardRepository->findActiveByTenant($tenant->key)
                : $this->boardRepository->findAllActive();
            $boards = $this->resourceAccessChecker->filterBoards($user, $candidates, $tenant?->key);
        }
        // Since 1.30.0 (spec 211): a scope step offers the button by what the host's scopes allow.
        $canCreate = $form->has('scope') || $this->canCreateBoard($user, $tenant?->key);

        return $this->render($this->templates['index'], [
            'page'           => $this->indexPage($boards, $canCreate),
            'boards'         => $boards,
            'boardForm'      => $form,
            'dashboardRoute' => $this->dashboardRoute,
            'tenant'         => $tenant,
            // Since 1.24: the New board dialog (shown to whoever may create a board here) and its <noscript> copy.
            'canCreateBoard'    => $canCreate,
            'boardFormFallback' => $fallbackForm,
            'openCreateBoard'   => $canCreate && $openCreate,
        ], new Response(status: $status));
    }

    /**
     * Since 2.0.0 (TB-4): the view model of the index page. Since 2.1.1 one New board: in the header when there are boards,
     * else in the empty state, which the scaffold draws whenever there are no rows and which takes the same `$canCreate`.
     * It is the page's main action, so its variant is explicit: `nowo_ui_kit.action_variants` is for actions without one.
     *
     * @param list<TaskBoard> $boards
     */
    private function indexPage(array $boards, bool $canCreate): ListPage
    {
        // Since 2.0.2: a toolbar action draws its kind's icon only when the host maps the kind, so the plus stays for the
        // rest, as Tabler for ux_icon: a bare name becomes `bi:plus` there, a 500 on a host that imported Tabler only.
        $twig        = $this->container->get('twig');
        $globals     = $twig instanceof Environment ? $twig->getGlobals() : [];
        $actionIcons = $globals['nowo_ui_kit_action_icons'] ?? null;
        $plus        = is_array($actionIcons) && isset($actionIcons['create']) ? null : (($globals['nowo_ui_kit_icon_set'] ?? null) === 'ux_icon' ? 'tabler:plus' : 'plus');
        $newBoard    = static fn (string $testid): Action => new Action(
            kind: 'create',
            label: TaskBoardPages::label('task_board.index.new_board'),
            icon: $plus,
            variant: 'primary',
            attributes: ['data-testid' => $testid, 'aria-haspopup' => 'dialog'],
            dialogId: TaskBoardPages::BOARD_CREATE_DIALOG,
        );
        $actions = $canCreate && $boards !== [] ? [$newBoard('nowo-task-board-new')] : [];
        if ($this->dashboardRoute !== null) {
            $actions[] = Action::route('custom', $this->dashboardRoute, [], TaskBoardPages::label('task_board.action.back'));
        }

        return new ListPage(
            new PageHeader(TaskBoardPages::label('task_board.index.title'), TaskBoardPages::label('task_board.index.subtitle'), $actions),
            [new Column('name', TaskBoardPages::label('task_board.index.your_boards'), required: true), new Column('views', TaskBoardPages::label('task_board.index.views'), align: 'end')],
            $boards,
            empty: new EmptyState(
                TaskBoardPages::label('task_board.index.no_boards'),
                $canCreate ? TaskBoardPages::label('task_board.index.empty_hint') : null,
                $canCreate ? $newBoard('nowo-task-board-new-empty') : null,
            ),
            bundle: TaskBoardPages::BUNDLE,
            layout: $this->pageLayout(),
            attributes: ['data-testid' => 'nowo-task-board-index'],
            rowAttributes: static fn (): array => ['data-testid' => 'nowo-task-board-list-item'],
        );
    }

    /** Since 2.0.0: `page.layout` of the scaffolds. */
    private function pageLayout(): ?string
    {
        $twig = $this->container->get('twig');

        return $twig instanceof Environment ? $this->pages->layout($twig) : null;
    }

    /**
     * Since 2.0.0 (`tenancy.open_single_board`, `tenancy.first_board_provisioner`): the board a tenant's index opens: its
     * only board, or a first one the provisioner creates. `?boards=1` keeps the list. Boards the user cannot see do not count.
     */
    private function openedBoard(UserInterface $user, ?TaskBoardTenant $tenant): ?TaskBoard
    {
        $openSingle = $this->opensSingleBoard();
        if (!$tenant instanceof TaskBoardTenant || (!$openSingle && !$this->firstBoardProvisioner instanceof FirstBoardProvisionerInterface) || $this->currentRequest()?->query->has('boards') === true) {
            return null;
        }
        $boards = $this->resourceAccessChecker->filterBoards($user, $this->boardRepository->findActiveByTenant($tenant->key), $tenant->key);
        if ($boards !== []) {
            return $openSingle && count($boards) === 1 ? reset($boards) : null;
        }

        return $this->firstBoardProvisioner instanceof FirstBoardProvisionerInterface && $this->mayProvisionBoard($user, $tenant)
            ? $this->firstBoardProvisioner->provision($tenant, $user)
            : null;
    }

    /**
     * Since 2.6.0 (TB-18): `open_single_board` of the tenant path the index was reached by (its route is
     * `<name_prefix><routes.index.name>`), else the global `tenancy.open_single_board`.
     */
    private function opensSingleBoard(): bool
    {
        $route = $this->currentRequest()?->attributes->get('_route');
        foreach ($this->openSingleBoardPaths as $namePrefix => $open) {
            if ($route === $namePrefix . $this->routes['index']['name']) {
                return $open;
            }
        }

        return $this->openSingleBoard;
    }

    /** A first board created on a visit asks what a create asks: the create check and the quota. */
    private function mayProvisionBoard(UserInterface $user, TaskBoardTenant $tenant): bool
    {
        return $this->canCreateBoard($user, $tenant->key)
            && $this->quota->refusal(TaskBoardQuotaCheckEvent::ACTION_BOARD_CREATE, $tenant->key, null, 1, $user) === null;
    }

    /**
     * The list check, with the tenant for a {@see TenantAwareTaskBoardAccessCheckerInterface} (since 2.0.0).
     */
    private function canListBoards(UserInterface $user, ?string $tenantKey): bool
    {
        return $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface
            ? $this->accessChecker->canListBoards($user, $tenantKey)
            : $this->accessChecker->canListBoards($user);
    }

    /** Since 2.0.0: a tenant whose boards the checker refuses to list answers 404, on its index and its create route. */
    private function denyUnlessCanListTenant(UserInterface $user, ?TaskBoardTenant $tenant): void
    {
        if ($tenant instanceof TaskBoardTenant && $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface && !$this->accessChecker->canListBoards($user, $tenant->key)) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * The create check, with the tenant for a {@see TenantAwareTaskBoardAccessCheckerInterface} (since 1.24).
     */
    private function canCreateBoard(UserInterface $user, ?string $tenantKey): bool
    {
        return $this->accessChecker instanceof TenantAwareTaskBoardAccessCheckerInterface
            ? $this->accessChecker->canCreateBoard($user, $tenantKey)
            : $this->accessChecker->canCreateBoard($user);
    }

    /**
     * Whether the request comes from a host dialog (`board.create_dialog_header`, since 1.24).
     */
    private function isDialogRequest(Request $request): bool
    {
        return $this->createDialogHeader !== null && $this->createDialogHeader !== '' && $request->headers->has($this->createDialogHeader);
    }

    /**
     * Since 1.24: the list or Gantt route of the board when the user's default view (board.default_view_resolver)
     * is one of them and the request did not ask for a view: a main GET request, not XHR, without `?view`, not
     * coming from another page of the same board (same-origin Referer under the board path).
     */
    private function defaultViewRedirect(UserInterface $user, TaskBoard $board): ?RedirectResponse
    {
        $request = $this->currentRequest();
        if (
            !$this->defaultViewResolver instanceof TaskBoardDefaultViewResolverInterface
            || !$request instanceof Request
            || !$request->isMethod(Request::METHOD_GET)
            || $request->query->has('view')
            || $request->isXmlHttpRequest()
            || $this->mainRequest() !== $request
        ) {
            return null;
        }

        $view = $this->defaultViewResolver->defaultView($user, $board);
        // Since 2.1.0 (spec 214, FR-024): also the calendar, when it is on.
        if ($view !== TaskBoardDefaultViewResolverInterface::VIEW_LIST && $view !== TaskBoardDefaultViewResolverInterface::VIEW_GANTT && ($view !== TaskBoardDefaultViewResolverInterface::VIEW_CALENDAR || !$this->calendarEnabled)) {
            return null;
        }

        try {
            $boardPath = $this->generateUrl($this->routes['board']['name'], ['boardId' => $board->getId()]);
            if ($this->comesFromBoard($request, $boardPath)) {
                return null;
            }
            $target = $this->generateUrl($this->routes[$view]['name'], ['boardId' => $board->getId()]);
        } catch (RoutingExceptionInterface) {
            // Audit EX-L1: a route the router cannot build keeps the board view instead of answering 500.
            return null;
        }

        // Audit EX-L1: the query is appended to the generated path, never merged into the route parameters, and
        // without route variables or reserved `_` keys (`_fragment`, `_locale`…).
        $query = array_filter(
            $request->query->all(),
            static fn (int|string $key): bool => !in_array((string) $key, self::DEFAULT_VIEW_DROPPED_KEYS, true) && !str_starts_with((string) $key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        return $this->redirect($query === [] ? $target : $target . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * The previous page is a page of this board on this site: the user chose the board view from there.
     */
    private function comesFromBoard(Request $request, string $boardPath): bool
    {
        $referer = parse_url((string) $request->headers->get('Referer', ''));
        if (!is_array($referer) || (isset($referer['host']) && $referer['host'] !== $request->getHost())) {
            return false;
        }
        $path = $referer['path'] ?? '';

        return $path === $boardPath || str_starts_with($path, $boardPath . '/');
    }

    private function mainRequest(): ?Request
    {
        /** @var RequestStack $requestStack */
        $requestStack = $this->container->get('request_stack');

        return $requestStack->getMainRequest();
    }

    /**
     * The new board form; with column sets it offers them instead of the default columns (since 1.11).
     *
     * @return FormInterface<TaskBoardFormData>
     */
    /**
     * @param list<BoardScope> $scopes
     *
     * @return FormInterface<mixed>
     */
    private function createBoardForm(UserInterface $user, ?string $tenantKey, array $scopes = []): FormInterface
    {
        $sets = $this->columnSetManager?->available($user, null, $tenantKey) ?? [];
        // Since 1.30.0 (spec 211): the scope the step opens on.
        $start = count($scopes) === 1 ? $scopes[0]->id : null;
        foreach ($scopes as $scope) {
            $start = $scope->preselected ? $scope->id : $start;
        }

        return $this->createForm(TaskBoardFormType::class, new TaskBoardFormData(scope: $start), [
            'column_set_choices'     => $this->columnSetChoices($sets),
            'scopes'                 => $scopes,
            'icon_too_long_message'  => $this->translator->trans('task_board.form.board.icon_too_long', [], 'NowoTaskBoardBundle'),
            'icon_invalid_message'   => $this->translator->trans('task_board.form.board.icon_invalid', [], 'NowoTaskBoardBundle'),
            'scope_required_message' => $this->translator->trans('task_board.form.board.scope_required', [], 'NowoTaskBoardBundle'),
        ] + $this->boardFormOptions);
    }

    /**
     * Since 1.30.0 (spec 211): the host's answer for the chosen scope, and the create check on that tenant. Null, with one form
     * error for both, so a refusal does not say whether a tenant exists.
     *
     * @param FormInterface<mixed> $form
     */
    private function scopedTenant(UserInterface $user, FormInterface $form, TaskBoardFormData $data): ?TaskBoardTenant
    {
        $scopeId = (string) $data->scope;
        $values  = $form->get('scopeFields')->get($scopeId)->getData();
        $target  = $this->scopeProvider?->resolve($user, $scopeId, is_array($values) ? $values : []);
        if ($target instanceof TaskBoardTenant && $this->canCreateBoard($user, $target->key)) {
            return $target;
        }
        $this->logRefused('board_scope');
        $form->addError(new FormError($this->translator->trans('task_board.form.board.scope_refused', [], 'NowoTaskBoardBundle')));

        return null;
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

    /**
     * Since 2.0.0: flashes are translated here. The layout prints them through UiKit's `flashes` partial, or a host's, and
     * neither knows this bundle's domain. Text that is no key (a host's refusal reason) stays as it is.
     */
    protected function addFlash(string $type, mixed $message): void
    {
        parent::addFlash($type, is_string($message) ? $this->translator->trans($message, [], TaskBoardPages::DOMAIN) : $message);
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
            'user_class'     => $this->userClass,
            'user_choices'   => $this->memberAssigner->assignableUsers($board),
            'user_reference' => $this->userReference,
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
     * Since 1.22.1: the errors of an import, translated when the result carries their translation (limits).
     *
     * @return list<string>
     */
    private function importErrors(TaskImportResult $result): array
    {
        $errors = [];
        foreach ($result->errors as $index => $error) {
            $translation = $result->errorTranslations[$index] ?? null;
            $errors[]    = $translation?->trans($this->translator) ?? $error;
        }

        return $errors;
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
            $this->logRefused('csrf_invalid');

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
            $this->logRefused('csrf_invalid');

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

    /**
     * Since 2.5.0 (TB-13): a board whose columns the user may manage (the column and column-set write routes): the board
     * for writing, then {@see TaskBoardResourceAccessChecker::canManageColumns()}, else 403.
     */
    private function findBoardForColumns(string $boardId, UserInterface $user): TaskBoard
    {
        $board = $this->findBoard($boardId, $user, true);
        if (!$this->resourceAccessChecker->canManageColumns($user, $board)) {
            throw $this->createAccessDeniedException('Column management denied.');
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
     * Loads a task and enforces per-task access; $forWrite also rejects tasks marked read-only by listeners; $detail
     * fetches the relations of the task page with it.
     */
    private function findTask(string $taskId, UserInterface $user, bool $forWrite = false, bool $detail = false): Task
    {
        // Since 1.21: the task page and panel load the task with its relations in a fixed number of queries.
        $task = $detail && $this->taskRepository instanceof BoundedTaskRepositoryInterface
            ? $this->taskRepository->findForDetail($taskId)
            : $this->taskRepository->findById($taskId);
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
     * The tasks a page of the board loads (since 1.21, REQ-PERF-001): at most `board.max_tasks` in position order with
     * their members, and how many the board has. A host task repository without {@see BoundedTaskRepositoryInterface}
     * keeps loading every task.
     *
     * @return array{0: list<Task>, 1: TaskWindow}
     */
    private function boardTasks(TaskBoard $board): array
    {
        if (!$this->taskRepository instanceof BoundedTaskRepositoryInterface) {
            $tasks = $this->taskRepository->findByBoard($board, true);

            return [$tasks, new TaskWindow(count($tasks), count($tasks), count($tasks))];
        }

        $tasks = $this->taskRepository->findByBoardWithin($board, true, $this->maxTasks);
        $total = count($tasks) < $this->maxTasks ? count($tasks) : $this->taskRepository->countByBoard($board, true);

        return [$tasks, new TaskWindow(count($tasks), $total, $this->maxTasks)];
    }

    /**
     * Today at midnight from the injected clock (default day of a time entry).
     */
    private function today(): DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    /**
     * REQ-OBS-001: one info line per successful write — the action and the ids of the board objects, never user data,
     * free text, file names or tokens.
     *
     * @param array<string, int|string|null> $ids
     */
    private function logWrite(string $action, array $ids): void
    {
        $this->logger->info('TaskBoard write: {action}', ['bundle' => 'nowo_task_board', 'action' => $action] + $ids);
    }

    /**
     * REQ-OBS-001: a refused write (invalid CSRF token) is logged as a warning without the token.
     */
    private function logRefused(string $reason): void
    {
        $this->logger->warning('TaskBoard write refused: {reason}', ['bundle' => 'nowo_task_board', 'reason' => $reason, 'route' => $this->currentRequest()?->attributes->get('_route')]);
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
