<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Controller;

use Nowo\ControllerKitBundle\Http\AttachmentDisposition;
use Nowo\ControllerKitBundle\Http\CsvStreamedResponse;
use Nowo\TaskBoardBundle\Form\MultiBoardTaskFilterType;
use Nowo\TaskBoardBundle\MultiBoard\Export\MultiBoardExportTable;
use Nowo\TaskBoardBundle\MultiBoard\Export\XlsxStreamWriter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardBoard;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardExportRows;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardPerson;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskFilter;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardTaskListInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Support\UserIdResolver;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\Listing\SortRequest;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\Pagination;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

use function array_filter;
use function array_map;
use function fclose;
use function fopen;
use function in_array;
use function is_array;
use function is_object;
use function is_scalar;
use function is_string;
use function str_starts_with;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Since 2.13.0 (Beacon D-20261112-01, `multi_board.enabled`): the tasks of every board a host lets the viewer read
 * ("All tasks" of an organisation), with a Board / Project column, filters, sort, server-side pagination, the workload per
 * person (`?view=people`) and a CSV / XLSX export of the filtered set.
 *
 * Access: the manage UI check (`canAccess()`, `canListBoards()`), then {@see MultiBoardTaskListInterface}, which asks
 * `multi_board.scope_provider` and re-checks every board with `canViewBoard()`. No provider or no board: an empty list,
 * and the export answers 404.
 */
final class TaskBoardMultiBoardController extends AbstractController
{
    /** The views of the page. */
    public const VIEW_TASKS = 'tasks';

    public const VIEW_PEOPLE = 'people';

    /** Route names (`multi_board.path` and `<path>/export.{format}`). */
    public const ROUTE = 'nowo_task_board_tasks';

    public const ROUTE_EXPORT = 'nowo_task_board_tasks_export';

    /** Per-page choices of the list. */
    public const PER_PAGE = [10, 25, 50, 100];

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, string> $templates
     */
    public function __construct(
        private readonly TaskBoardAccessCheckerInterface $accessChecker,
        private readonly MultiBoardTaskListInterface $tasks,
        private readonly MultiBoardExportTable $exportTable,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?string $dashboardRoute = null,
        private readonly int $perPage = 25,
        private readonly TaskBoardPages $pages = new TaskBoardPages(),
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function tasks(Request $request): Response
    {
        $user    = $this->requireUser();
        $viewer  = $this->viewer($request, $user);
        $query   = $request->query->all();
        $filter  = MultiBoardTaskFilter::fromQuery($query);
        $view    = ($query['view'] ?? null) === self::VIEW_PEOPLE ? self::VIEW_PEOPLE : self::VIEW_TASKS;
        $perPage = (int) ($query['per_page'] ?? $this->perPage);
        $perPage = in_array($perPage, self::PER_PAGE, true) ? $perPage : $this->perPage;
        $page    = (int) ($query['page'] ?? 1);

        $routeParams = $this->routeParams($request);
        $kept        = $filter->toQuery() + ($view === self::VIEW_PEOPLE ? ['view' => self::VIEW_PEOPLE] : []);
        $sorting     = new SortRequest($filter->sort, $filter->direction, MultiBoardTaskFilter::SORTS, 'sort', 'dir', 'page');

        if ($view === self::VIEW_PEOPLE) {
            $workload  = $this->tasks->workload($viewer, $filter);
            $boards    = $workload->boards;
            $assignees = $workload->assignees;
            $columns   = $workload->columns;
            $rows      = $workload->rows;
            $partial   = $workload->partial;
            $total     = null;
        } else {
            $result    = $this->tasks->page($viewer, $filter, $page, $perPage);
            $boards    = $result->boards;
            $assignees = $result->assignees;
            $columns   = $result->columns;
            $rows      = $result->rows;
            $partial   = $result->partial;
            $total     = $result->total;
            $page      = $result->page;
        }

        // A board outside the checked scope was ignored by the query: the links and the form forget it too.
        if ($filter->boardId !== null && !in_array($filter->boardId, array_map(static fn (MultiBoardBoard $board): string => $board->id, $boards), true)) {
            $filter = $filter->withoutBoard();
            $kept   = $filter->toQuery() + ($view === self::VIEW_PEOPLE ? ['view' => self::VIEW_PEOPLE] : []);
        }
        $pagination = $total !== null && $total > 0 ? Pagination::fromTotal($total, $page, $perPage, self::PER_PAGE, $this->perPage, self::ROUTE, $kept + $sorting->query() + ['per_page' => (string) $perPage], $routeParams) : null;

        $form = $this->formFactory()->createNamed('', MultiBoardTaskFilterType::class, [
            'view'     => $view === self::VIEW_PEOPLE ? self::VIEW_PEOPLE : null,
            'sort'     => $filter->sort,
            'dir'      => $filter->direction,
            'q'        => $filter->search,
            'board'    => $filter->boardId,
            'assignee' => $filter->assigneeId,
            'column'   => $filter->column,
            'status'   => $filter->status,
            'priority' => $filter->priority?->value,
            'due_from' => $filter->dueFrom?->format('Y-m-d'),
            'due_to'   => $filter->dueTo?->format('Y-m-d'),
            'label'    => $filter->label,
            'overdue'  => $filter->overdue,
        ], [
            'boards'    => $this->choices($boards, static fn (MultiBoardBoard $board): array => [$board->label, $board->id]),
            'assignees' => ['task_board.multi.filter.unassigned' => MultiBoardTaskFilter::UNASSIGNED] + $this->choices($assignees, static fn (MultiBoardPerson $person): array => [$person->label, $person->id]),
            'columns'   => $this->choices($columns, static fn (string $name): array => [$name, $name]),
        ]);

        return $this->render($this->templates['tasks'], [
            'page'           => $this->listPage($view, $rows, $boards === [], $filter, $sorting, $pagination, $routeParams, $form->createView()),
            'view'           => $view,
            'boards'         => $boards,
            'filter'         => $filter,
            'total'          => $total,
            'partial'        => $partial,
            'taskRoute'      => $this->routes['task']['name'] ?? 'nowo_task_board_task',
            'boardRoute'     => $this->routes['list']['name'] ?? 'nowo_task_board_list',
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    public function export(Request $request, string $format): Response
    {
        $user   = $this->requireUser();
        $filter = MultiBoardTaskFilter::fromQuery($request->query->all());
        $export = $this->tasks->export($this->viewer($request, $user), $filter);
        if (!$export instanceof MultiBoardExportRows) {
            throw $this->createNotFoundException();
        }
        $this->logger->info('TaskBoard export: {format}', ['bundle' => 'nowo_task_board', 'format' => $format, 'rows' => $export->isTruncated() ? $export->maxRows : $export->matching, 'truncated' => $export->isTruncated()]);

        $filename = 'tasks-' . $this->clock->now()->format('Y-m-d') . '.' . $format;
        $headers  = ['Cache-Control' => 'no-store, private'];
        if ($export->isTruncated()) {
            $headers['X-Task-Board-Export-Truncated'] = (string) $export->maxRows;
        }
        $table = $this->exportTable;

        if ($format === 'csv') {
            return new CsvStreamedResponse($filename, static function () use ($export, $table): iterable {
                foreach ($export->rows() as $row) {
                    yield $table->cells($row);
                }
            }, $table->header(), noStore: true, headers: $headers);
        }

        return new StreamedResponse(static function () use ($export, $table): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return; // @codeCoverageIgnore
            }
            $writer = new XlsxStreamWriter($out, $table->header());
            foreach ($export->rows() as $row) {
                $writer->addRow($table->cells($row));
            }
            $writer->close();
            fclose($out);
        }, Response::HTTP_OK, [
            'Content-Type'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'    => AttachmentDisposition::attachment($filename),
            'X-Content-Type-Options' => 'nosniff',
        ] + $headers);
    }

    /**
     * @param list<mixed> $rows
     * @param array<string, string> $routeParams
     */
    private function listPage(string $view, array $rows, bool $noBoards, MultiBoardTaskFilter $filter, SortRequest $sorting, ?Pagination $pagination, array $routeParams, FormView $filters): ListPage
    {
        $twig    = $this->container->get('twig');
        $iconSet = $twig instanceof Environment ? $twig->getGlobals()['nowo_ui_kit_icon_set'] ?? null : null;
        $route   = self::ROUTE;
        $tabs    = [
            ['label' => TaskBoardPages::label('task_board.multi.view.tasks'), 'href' => $this->generateUrl($route, $routeParams + $filter->toQuery()), 'icon' => 'list', 'active' => $view === self::VIEW_TASKS],
            ['label' => TaskBoardPages::label('task_board.multi.view.people'), 'href' => $this->generateUrl($route, $routeParams + $filter->toQuery() + ['view' => self::VIEW_PEOPLE]), 'icon' => 'users', 'active' => $view === self::VIEW_PEOPLE],
        ];
        $actions = [];
        if (!$noBoards) {
            foreach (['csv', 'xlsx'] as $format) {
                $actions[] = [
                    'href'       => $this->generateUrl(self::ROUTE_EXPORT, $routeParams + ['format' => $format] + $filter->toQuery() + $sorting->query()),
                    'label'      => TaskBoardPages::label('task_board.multi.export.' . $format),
                    'icon'       => 'download',
                    'attributes' => ['data-testid' => 'nowo-task-multi-export-' . $format, 'download' => true],
                ];
            }
        }
        $header = TaskBoardPages::header([
            'title'   => TaskBoardPages::label('task_board.multi.title'),
            'intro'   => TaskBoardPages::label('task_board.multi.intro'),
            'actions' => $actions,
            'back'    => $this->dashboardRoute !== null ? $this->generateUrl($this->dashboardRoute) : null,
            'tabs'    => $tabs,
        ], is_string($iconSet) ? $iconSet : 'tabler-icons');

        $columns = $view === self::VIEW_PEOPLE
            ? [
                new Column('person', TaskBoardPages::label('task_board.multi.column.person'), required: true),
                new Column('open', TaskBoardPages::label('task_board.multi.column.open'), align: 'end'),
                new Column('overdue', TaskBoardPages::label('task_board.multi.column.overdue'), align: 'end'),
                new Column('done', TaskBoardPages::label('task_board.multi.column.done'), align: 'end'),
                new Column('estimate', TaskBoardPages::label('task_board.multi.column.estimate'), align: 'end', priority: 2),
                new Column('logged', TaskBoardPages::label('task_board.multi.column.logged'), align: 'end', priority: 2),
            ]
            : [
                new Column('number', TaskBoardPages::label('task_board.multi.column.number'), sortable: true, priority: 3),
                new Column('title', TaskBoardPages::label('task_board.multi.column.title'), sortable: true, required: true),
                new Column('board', TaskBoardPages::label('task_board.multi.column.board'), sortable: true),
                new Column('column', TaskBoardPages::label('task_board.multi.column.column'), sortable: true, priority: 2),
                new Column('assignees', TaskBoardPages::label('task_board.multi.column.assignees'), priority: 1),
                new Column('priority', TaskBoardPages::label('task_board.multi.column.priority'), sortable: true, priority: 2, sortFirst: 'desc'),
                new Column('due', TaskBoardPages::label('task_board.multi.column.due'), sortable: true),
            ];

        return new ListPage(
            $header,
            $columns,
            $rows,
            filters: $filters,
            pagination: $pagination,
            empty: $noBoards
                ? new EmptyState(TaskBoardPages::label('task_board.multi.empty_scope'), TaskBoardPages::label('task_board.multi.empty_scope_hint'))
                : new EmptyState(TaskBoardPages::label('task_board.multi.empty'), $filter->isActive() ? TaskBoardPages::label('task_board.multi.empty_hint') : null),
            filtersClearHref: $this->generateUrl($route, $routeParams + ($view === self::VIEW_PEOPLE ? ['view' => self::VIEW_PEOPLE] : [])),
            caption: TaskBoardPages::label($view === self::VIEW_PEOPLE ? 'task_board.multi.caption_people' : 'task_board.multi.caption'),
            bundle: TaskBoardPages::BUNDLE,
            layout: $this->pageLayout(),
            attributes: ['data-testid' => 'nowo-task-multi-' . $view],
            captionVisible: false,
            sorting: $view === self::VIEW_TASKS ? $sorting : null,
            rowAttributes: static fn (): array => ['data-testid' => 'nowo-task-multi-row'],
        );
    }

    /**
     * Label => value choices; two items with one label stay apart (the value is added to the label).
     *
     * @template T
     *
     * @param list<T> $items
     * @param callable(T): array{string, string} $pair
     *
     * @return array<string, string>
     */
    private function choices(array $items, callable $pair): array
    {
        $choices = [];
        foreach ($items as $item) {
            [$label, $value]                                                                                         = $pair($item);
            $choices[isset($choices[$label]) && $choices[$label] !== $value ? $label . ' (' . $value . ')' : $label] = $value;
        }

        return $choices;
    }

    private function viewer(Request $request, UserInterface $user): MultiBoardViewer
    {
        return new MultiBoardViewer(UserIdResolver::getId($user), $this->routeParams($request));
    }

    /**
     * The route parameters of the page other than `_` ones and `format` (e.g. `{organisation}` in `multi_board.path`).
     *
     * @return array<string, string>
     */
    private function routeParams(Request $request): array
    {
        $params = $request->attributes->get('_route_params');
        $params = is_array($params) ? $params : [];

        return array_map(static fn (mixed $value): string => (string) $value, array_filter(
            $params,
            static fn (mixed $value, mixed $key): bool => is_string($key) && !str_starts_with($key, '_') && $key !== 'format' && is_scalar($value),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user) || !$this->accessChecker->canListBoards($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function formFactory(): FormFactoryInterface
    {
        /** @var FormFactoryInterface $factory */
        $factory = $this->container->get('form.factory');

        return $factory;
    }

    private function pageLayout(): ?string
    {
        $twig = $this->container->get('twig');

        return $twig instanceof Environment ? $this->pages->layout($twig) : null;
    }
}
