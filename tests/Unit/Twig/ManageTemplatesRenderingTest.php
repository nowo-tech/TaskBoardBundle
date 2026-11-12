<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use DateTimeImmutable;
use DOMElement;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\Extension\SwitchExtension;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TaskBoardBundle\Board\BoardScope;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskCommentFormData;
use Nowo\TaskBoardBundle\Dto\TaskDependencyFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Dto\TaskTimeEntryFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskDependency;
use Nowo\TaskBoardBundle\Entity\TaskDocument;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Entity\TaskMember;
use Nowo\TaskBoardBundle\Entity\TaskTimeEntry;
use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Enum\WeekStart;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetApplyFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskCommentFormType;
use Nowo\TaskBoardBundle\Form\TaskDependencyFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Form\TaskPriorityFormType;
use Nowo\TaskBoardBundle\Form\TaskTimeEntryFormType;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Support\TaskNavigator;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\Theme\Theme;
use Nowo\UiKitBundle\Twig\ThemeExtension;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

use function count;

/**
 * Renders every full manage page with real entities and strict_variables (as in the dev
 * environment), so a template reading a property the entities do not expose fails here
 * instead of as an HTTP 500 in the host application.
 */
final class ManageTemplatesRenderingTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../src/Resources/views';

    private const VENDOR = __DIR__ . '/../../../vendor';

    private TestUser $user;

    private TaskBoard $board;

    private BoardColumn $todo;

    private Task $task;

    protected function setUp(): void
    {
        $this->user  = new TestUser('1', 'owner@example.com');
        $this->board = new TaskBoard('Roadmap', 'roadmap', $this->user, 'Quarterly roadmap');
        $this->todo  = new BoardColumn($this->board, 'Todo', 0);
        $done        = new BoardColumn($this->board, 'Done', 1);
        $this->board->addColumn($this->todo)->addColumn($done);

        $this->task = new Task(
            $this->board,
            'Ship it',
            $this->user,
            column: $this->todo,
            description: 'Release notes',
            priority: TaskPriority::High,
            estimatedMinutes: 90,
            dueAt: new DateTimeImmutable('2026-12-01'),
            tags: ['api', 'ui'],
        );
        $this->board->getTasks()->add($this->task);
    }

    public function testTaskDetailPageRendersWithLoggedTime(): void
    {
        $task = $this->task;
        // 150 s from TimeTrack timers + 25 min of manual entries = 27 whole minutes.
        $task->addTimeSeconds(150);
        $task->getTimeEntries()->add(new TaskTimeEntry($task, $this->user, 25, new DateTimeImmutable()));

        $subtask = new Task($this->board, 'Write docs', $this->user, column: $this->todo, parent: $task, tags: ['docs']);
        $nested  = new Task($this->board, 'Proofread', $this->user, parent: $subtask);
        $subtask->getSubtasks()->add($nested);
        $task->getSubtasks()->add($subtask);
        $subtask->markCompleted();

        $task->addMember(new TaskMember($task, $this->user, TaskMemberRole::Assignee));
        $task->addLink(new TaskLink($task, TaskLinkType::MergeRequest, 'https://gitlab.example.com/g/p/-/merge_requests/1', 'MR !1', '1'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::Priority, 'normal', 'high'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::Tags, '["legacy"]', '["api","ui"]'));
        $task->getOutgoingDependencies()->add(new TaskDependency($task, $subtask, TaskDependencyType::Blocks));
        $task->getDocuments()->add(new TaskDocument($task, 'Spec', "Line 1\nLine 2", $this->user));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', [
            'task'             => $task,
            'taskForm'         => $this->taskForm(TaskFormData::class, false)->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($task),
            'subtaskTotal'     => 1,
            'subtaskDone'      => 1,
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
        ] + $this->taskActionForms($task));

        self::assertStringContainsString('<strong>27 min</strong>', $html);
        // 1.14.1: the detail's widgets drawn without a label are named (axe "label").
        foreach (['dueAt', 'estimatedMinutes', 'tags', 'description'] as $field) {
            self::assertMatchesRegularExpression('/<(input|textarea)[^>]*name="task_form\[' . $field . '\]"[^>]*aria-label="[^"]+"|<(input|textarea)[^>]*aria-label="[^"]+"[^>]*name="task_form\[' . $field . '\]"/', $html, $field);
        }
        // REQ-TWIG-005: CSRF-only actions and the priority modal render from Form views.
        self::assertStringContainsString('id="nowo-task-advance-form"', $html);
        self::assertStringContainsString('id="nowo-task-link-delete-form"', $html);
        self::assertStringContainsString('data-nowo-task-board-task-links-modal-target="deleteForm"', $html);
        self::assertMatchesRegularExpression('/name="priority"[^>]*value="high" checked="checked"/', $html);
        self::assertStringContainsString('data-nowo-task-board-task-priority-modal-target="firstOption"', $html);
        self::assertStringContainsString('Ship it', $html);
        self::assertStringContainsString('Write docs', $html);
        self::assertStringContainsString('Proofread', $html);
        self::assertStringContainsString('MR !1', $html);
        self::assertStringContainsString('Spec', $html);
        self::assertStringContainsString('<span class="nowo-task-activity__old">legacy</span>', $html);
        self::assertStringContainsString('<span>api, ui</span>', $html);
    }

    public function testTaskColourPickerHistoryAndNativeDialogs(): void
    {
        $task = $this->task;
        $task->setColor('#3b82f6');
        $task->addMember(new TaskMember($task, $this->user, TaskMemberRole::Assignee));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::Color, null, '#3b82f6'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::Color, '#3b82f6'));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', [
            'task'             => $task,
            'taskForm'         => $this->taskForm(TaskFormData::class, false)->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($task),
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
        ] + $this->taskActionForms($task));

        // The colour row: hidden value, "No colour" swatch, palette and the unmapped custom colour input.
        self::assertMatchesRegularExpression('/<input type="hidden" id="task_form_color" name="task_form\[color\]" data-nowo-task-board-picker-target="input" value="#3b82f6"/', $html);
        self::assertStringContainsString('nowo-task-picker__swatch nowo-task-picker__swatch--none', $html);
        self::assertMatchesRegularExpression('/<input type="color" id="task_form_customColor" name="task_form\[customColor\]"[^>]*data-nowo-task-board-picker-target="custom"/', $html);
        self::assertStringContainsString('data-value="#8b5cf6"', $html);
        // The activity shows the colours as swatches, and "No colour" when it was removed.
        self::assertStringContainsString('<span class="nowo-task-activity__swatch" data-nowo-task-vars="{&quot;--nowo-task-swatch&quot;:&quot;#3b82f6&quot;}" aria-hidden="true"></span>#3b82f6', $html);
        self::assertStringContainsString('<span>task_board.form.task.no_color</span>', $html);
        // People, priority and links are native dialogs (no Bootstrap modal markup).
        self::assertSame(3, substr_count($html, '<dialog'));
        self::assertStringContainsString('data-nowo-task-board-task-members-modal-target="modal"', $html);
        self::assertStringContainsString('class="nowo-task-members__item"', $html);
        self::assertStringContainsString('class="nowo-task-members__role nowo-task-members__role--assignee"', $html);
        self::assertMatchesRegularExpression('/<form[^>]*class="nowo-task-members__add"[^>]*id="nowo-task-members-add-[^"]+"|<form[^>]*id="nowo-task-members-add-[^"]+"[^>]*class="nowo-task-members__add"/', $html);
        // 1.16.1: Close and Add member share the dialog footer; the submit button names the add form (1.26: the footer
        // is outside the scrolling body).
        self::assertMatchesRegularExpression('/<footer class="[^"]*nowo-task-dialog__footer nowo-task-dialog__actions"[^>]*>\s*<button type="button" class="[^"]*" data-nowo-ui-dialog-close>[^<]*<\/button>\s*<button type="submit" form="nowo-task-members-add-[^"]+" class="[^"]*nowo-task-members__add-button">/', $html);
        self::assertStringContainsString('data-action="change-&gt;nowo-task-board-task-priority-modal#select"', $html);
        self::assertStringNotContainsString('class="modal', $html);
        self::assertStringNotContainsString('modal-backdrop', $html);
        self::assertStringNotContainsString(' style="', $html);
        $this->assertDialogAnatomy($html, 3);
    }

    public function testBoardCardAndGanttBarUseTheTaskColour(): void
    {
        $this->task->setColor('#ef4444');
        $plain = new Task($this->board, 'Plain', $this->user, column: $this->todo, dueAt: new DateTimeImmutable('+1 day'));
        $this->board->getTasks()->add($plain);

        $board = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', $this->boardContext(['tasks' => [$this->task, $plain]]));
        self::assertStringContainsString('nowo-task-card nowo-task-card--colored"', $board);
        self::assertStringContainsString('data-nowo-task-vars="{&quot;--nowo-task-card-color&quot;:&quot;#ef4444&quot;}"', $board);
        self::assertSame(1, substr_count($board, 'nowo-task-card--colored'));
        // The add task dialog is native and offers the colour.
        self::assertStringContainsString('nowo-task-dialog nowo-task-dialog--native nowo-task-dialog--wide', $board);
        self::assertStringContainsString('name="task_form[customColor]"', $board);
        self::assertStringNotContainsString('class="modal', $board);
        self::assertStringNotContainsString(' style="', $board);

        $gantt = $this->twig()->render('@NowoTaskBoardBundle/manage/gantt.html.twig', [
            'board'          => $this->board,
            'dashboardRoute' => null,
            'timeline'       => (new TaskGanttBuilder())->build([$this->task, $plain]),
        ]);
        self::assertSame(1, substr_count($gantt, 'nowo-task-gantt__bar--colored'));
        self::assertStringContainsString('&quot;--nowo-task-bar-color&quot;:&quot;#ef4444&quot;', $gantt);
        self::assertStringNotContainsString(' style="', $gantt);
    }

    public function testTaskDetailRendersTheSubtaskTreeAndHostObjectLinks(): void
    {
        $task = $this->task;
        $a    = new Task($this->board, 'Level 1', $this->user, column: $this->todo, parent: $task);
        $a1   = new Task($this->board, 'Level 2', $this->user, parent: $a);
        $a1x  = new Task($this->board, 'Level 3', $this->user, parent: $a1);
        $a1xy = new Task($this->board, 'Level 4', $this->user, parent: $a1x);
        $a1xy->markCompleted();
        $task->addLink(TaskLink::forHostObject($task, 'issue', '12'));
        $task->addLink(TaskLink::forHostObject($task, 'order', '7', 'Order 7'));
        $tree = TaskTree::fromTasks([$task, $a, $a1, $a1x, $a1xy]);

        $resolver = new class implements TaskObjectLinkResolverInterface {
            public function supports(string $objectType): bool
            {
                return $objectType === 'issue';
            }

            public function label(string $objectType, string $objectId): string
            {
                return 'BEACON-' . $objectId . ' Crash';
            }

            public function url(string $objectType, string $objectId): string
            {
                return '/projects/1/issues/' . $objectId;
            }

            public function badge(string $objectType, string $objectId): TaskObjectBadge
            {
                return new TaskObjectBadge('Resolved', 'success');
            }
        };

        $html = $this->twig(new TaskObjectLinkResolver([$resolver]))->render('@NowoTaskBoardBundle/manage/task.html.twig', [
            'task'             => $task,
            'taskForm'         => $this->taskForm(TaskFormData::class, false)->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($task),
            'subtaskTotal'     => $tree->progress($task)->total,
            'subtaskDone'      => $tree->progress($task)->done,
            'taskTree'         => $tree,
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
        ] + $this->taskActionForms($task));

        foreach (['Level 1', 'Level 2', 'Level 3', 'Level 4'] as $title) {
            self::assertStringContainsString($title, $html);
        }
        self::assertStringContainsString('aria-level="4"', $html);
        // Level 1 counts its 3 descendants, one of them done.
        self::assertStringContainsString('1/3', $html);
        self::assertStringContainsString('<a href="/projects/1/issues/12" class="nowo-task-link__label">BEACON-12 Crash</a>', $html);
        self::assertStringContainsString('nowo-task-link__badge nowo-task-badge--success">Resolved', $html);
        self::assertStringContainsString('<span class="nowo-task-link__label">Order 7</span>', $html);
    }

    public function testBoardCardsShowTheSubtaskCounter(): void
    {
        $subtask = new Task($this->board, 'Child', $this->user, column: $this->todo, parent: $this->task);
        $subtask->markCompleted();
        $choices = ['Todo' => $this->todo->getId()];

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'taskTree'       => TaskTree::fromTasks([$this->task, $subtask]),
            'columnForm'     => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'editColumnForm' => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'taskForm'       => $this->factory()->create(TaskFormType::class, new TaskFormData(columnId: $this->todo->getId()), [
                'column_choices'   => $choices,
                'rich_description' => false,
                'tag_input'        => false,
            ])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('nowo-task-card__subtasks', $html);
        self::assertStringContainsString('1/1', $html);
        self::assertStringNotContainsString('>Child<', $html);
    }

    public function testReadOnlyBoardHidesWriteControls(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'canEditBoard'   => false,
            'columnForm'     => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'editColumnForm' => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'taskForm'       => $this->factory()->create(TaskFormType::class, new TaskFormData(columnId: $this->todo->getId()), [
                'column_choices'   => ['Todo' => $this->todo->getId()],
                'rich_description' => false,
                'tag_input'        => false,
            ])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('Ship it', $html);
        self::assertStringNotContainsString('nowo-task-board__add-task-btn', $html);
        self::assertStringNotContainsString('nowo-task-board__add-column-btn', $html);
        self::assertStringNotContainsString('nowo-task-board__column-edit', $html);
        self::assertStringNotContainsString('/import', $html);
    }

    public function testTaskDetailPageShowsZeroWithoutLoggedTime(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', [
            'task'             => $this->task,
            'taskForm'         => $this->taskForm(TaskFormData::class, false)->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($this->task),
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
        ] + $this->taskActionForms($this->task));

        self::assertStringContainsString('<strong>0 min</strong>', $html);
    }

    public function testIndexPageRenders(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/index.html.twig', [
            'page'           => new ListPage(new PageHeader('task_board.index.title'), [new Column('name'), new Column('views')], [$this->board], bundle: TaskBoardPages::BUNDLE, layout: TaskBoardPages::LEGACY_ADMIN_BRIDGE),
            'canCreateBoard' => true,
            'boards'         => [$this->board],
            'boardForm'      => $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('Roadmap', $html);
        // Since 1.26 (dialog rule v2): New board's Cancel and Create sit in the footer, bound to the form.
        $this->assertDialogAnatomy($html, 1);
        $create = (new Crawler($html))->filter('dialog[data-testid="nowo-task-board-create-dialog"]');
        self::assertSame('nowo-task-board-create-form', $create->filter('form')->attr('id'));
        self::assertCount(0, $create->filter('form .nowo-task-dialog__actions'));
        self::assertSame('nowo-task-board-create-form', $create->filter('[data-testid="nowo-task-board-create-submit"]')->attr('form'));
    }

    /** Since 1.26: the create form alone (the 422 answer of a host dialog) keeps its action row, marked for the host. */
    public function testTheBoardCreateFormAloneKeepsItsActionsForAHostDialog(): void
    {
        $form = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView();
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_form.html.twig', ['form' => $form, 'in_dialog' => true]);

        $actions = (new Crawler($html))->filter('form#nowo-task-board-create-form .nowo-task-dialog__actions');
        self::assertSame('', $actions->attr('data-nowo-ui-dialog-actions'));
        self::assertCount(2, $actions->filter('button'));

        $page  = $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_form.html.twig', ['form' => $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView(), 'id_prefix' => 'nojs-']);
        $plain = (new Crawler($page))->filter('form#nojs-nowo-task-board-create-form .nowo-task-dialog__actions');
        self::assertNull($plain->attr('data-nowo-ui-dialog-actions'));
        self::assertCount(1, $plain->filter('button'));
    }

    /** Since 2.5.0 (TB-15): a page outside any tenant posts the form to its own action, with its own ids, and the footer partial. */
    public function testTheBoardCreateFormAndActionsTakeAHostActionAndIds(): void
    {
        $form    = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView();
        $html    = $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_form.html.twig', ['form' => $form, 'in_dialog' => true, 'with_actions' => false, 'action' => '/dashboard/tasks/boards', 'form_id' => 'host-new-board', 'testid' => 'host-new-board-form']);
        $created = (new Crawler($html))->filter('form#host-new-board');
        self::assertSame('/dashboard/tasks/boards', $created->attr('action'));
        self::assertSame('host-new-board-form', $created->attr('data-testid'));
        self::assertCount(0, $created->filter('.nowo-task-dialog__actions'));

        $form    = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView();
        $actions = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form, 'form_id' => 'host-new-board', 'testid' => 'host-create', 'close_attribute' => 'data-confirm-dialog-close']) . '</div>');
        self::assertSame('host-new-board', $actions->filter('[data-testid="host-create"]')->attr('form'));
        self::assertCount(1, $actions->filter('button[data-confirm-dialog-close]'));
        self::assertCount(0, $actions->filter('[data-nowo-ui-dialog-back]'), 'no scope step without a scope provider');

        $form   = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView();
        $noBack = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form, 'no_cancel' => true, 'close_attribute' => 'onclick']) . '</div>');
        self::assertCount(1, $noBack->filter('button'));
        self::assertSame('nowo-task-board-create-form', $noBack->filter('[data-testid="nowo-task-board-create-submit"]')->attr('form'));

        $form     = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView();
        $defaults = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form, 'close_attribute' => 'onclick']) . '</div>');
        self::assertCount(1, $defaults->filter('button[data-nowo-ui-dialog-close]'), 'an attribute that is not data-* falls back to the kit\'s close');
        self::assertStringNotContainsString('onclick', $defaults->html());

        // Audit IM-I9: a trailing line break is not a data-* name.
        $form    = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView();
        $newline = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form, 'close_attribute' => "data-x\n"]) . '</div>');
        self::assertCount(1, $newline->filter('button[data-nowo-ui-dialog-close]'));
        self::assertCount(0, $newline->filter('button[data-x]'));
    }

    /** Since 2.7.0 (TB-15b): Back and Cancel carry their verb icons, and the steps' testids and the icons are the host's to rename. */
    public function testTheBoardCreateStepsTakeHostTestidsAndTheActionsCarryIcons(): void
    {
        $scopes = ['scopes' => [new BoardScope('personal', 'Personal'), new BoardScope('team', 'Team')], 'advanced_slug' => true];

        $form     = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData(), $scopes)->createView();
        $defaults = new Crawler($this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_form.html.twig', ['form' => $form, 'in_dialog' => true]));
        foreach (['scopes', 'next', 'details', 'advanced', 'back'] as $step) {
            self::assertCount(1, $defaults->filter('[data-testid="nowo-task-board-create-' . $step . '"]'), $step);
        }
        self::assertCount(1, $defaults->filter('[data-nowo-ui-dialog-back] .nowo-task-icon i.ti-arrow-left'));
        self::assertCount(1, $defaults->filter('[data-nowo-ui-dialog-close] .nowo-task-icon i.ti-x'));
        self::assertCount(1, $defaults->filter('[data-testid="nowo-task-board-create-submit"] .nowo-task-icon i.ti-plus'));
        self::assertSame('nowo-task-board-create-form nowo-task-dialog__body', $defaults->filter('form')->attr('class'));

        $form = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData(), $scopes)->createView();
        $host = new Crawler($this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_form.html.twig', ['form' => $form, 'in_dialog' => true, 'step_testid_prefix' => 'tasks-board-create', 'form_class' => 'grid gap-4', 'back_icon' => 'chevron-left', 'cancel_icon' => false, 'create_icon' => 'check']));
        foreach (['scopes', 'next', 'details', 'advanced', 'back'] as $step) {
            self::assertCount(1, $host->filter('[data-testid="tasks-board-create-' . $step . '"]'), $step);
        }
        self::assertCount(0, $host->filter('[data-testid^="nowo-task-board-create-"]:not([data-testid$="-submit"]):not([data-testid$="-form"])'));
        self::assertSame('nowo-task-board-create-form nowo-task-dialog__body grid gap-4', $host->filter('form')->attr('class'));
        self::assertCount(1, $host->filter('[data-nowo-ui-dialog-back] i.ti-chevron-left'));
        self::assertCount(0, $host->filter('[data-nowo-ui-dialog-close] .nowo-task-icon'));
        self::assertCount(1, $host->filter('[data-testid="nowo-task-board-create-submit"] i.ti-check'));

        $form    = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData(), $scopes)->createView();
        $actions = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form]) . '</div>');
        self::assertCount(1, $actions->filter('[data-testid="nowo-task-board-create-back"] .nowo-task-icon i.ti-arrow-left'));
        self::assertCount(1, $actions->filter('[data-nowo-ui-dialog-close] .nowo-task-icon i.ti-x'));
        self::assertCount(1, $actions->filter('[data-testid="nowo-task-board-create-submit"] .nowo-task-icon i.ti-plus'));

        $form   = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData(), $scopes)->createView();
        $prefix = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form, 'step_testid_prefix' => 'tasks-board-create', 'back_icon' => false, 'cancel_icon' => 'circle', 'create_icon' => false]) . '</div>');
        self::assertCount(1, $prefix->filter('[data-testid="tasks-board-create-back"]'));
        self::assertCount(0, $prefix->filter('[data-nowo-ui-dialog-back] .nowo-task-icon'));
        self::assertCount(1, $prefix->filter('[data-nowo-ui-dialog-close] i.ti-circle'));
        self::assertCount(0, $prefix->filter('button[type=submit] .nowo-task-icon'));

        $form  = $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData(), $scopes)->createView();
        $named = new Crawler('<div>' . $this->twig()->render('@NowoTaskBoardBundle/manage/_board_create_actions.html.twig', ['form' => $form, 'step_testid_prefix' => 'ignored', 'back_testid' => 'host-back']) . '</div>');
        self::assertCount(1, $named->filter('[data-nowo-ui-dialog-back][data-testid="host-back"]'));
    }

    /** Since 1.19: a plain-text description is shown rendered, with "Edit description" for editors only. */
    public function testAPlainTextDescriptionIsShownRenderedWithAnEditDisclosure(): void
    {
        $this->task->setDescription("Line <b>one</b>\nline two");
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['panel' => true]);

        self::assertStringContainsString('<div class="nowo-task-detail__description-view nowo-task-text">Line &lt;b&gt;one&lt;/b&gt;<br>', $html);
        self::assertStringContainsString('nowo-task-detail__description-edit', $html);
        self::assertStringContainsString('task_board.task.edit_description', $html);

        $readOnly = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['panel' => true, 'canEditTask' => false]);
        self::assertStringContainsString('nowo-task-detail__description-view', $readOnly);
        self::assertStringNotContainsString('nowo-task-detail__description-edit', $readOnly);

        $this->task->setDescription(null);
        $empty = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['panel' => true]);
        self::assertStringNotContainsString('nowo-task-detail__description-view', $empty);
    }

    public function testATaskOffersImportSubtasksWithItsHelpToEditorsOnly(): void
    {
        $form = $this->factory()->create(TaskImportFormType::class, new TaskImportFormData(TaskImportSource::TaskBoardCsv), ['action' => '/task/import'])->createView();
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['subtaskImportForm' => $form, 'panel' => true]);

        self::assertStringContainsString('nowo-task-subtask-import', $html);
        self::assertStringContainsString('data-dialog="subtask-import"', $html);
        self::assertStringContainsString('action="/task/import"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('task_board.import.help.intro_task', $html);
        self::assertMatchesRegularExpression('/<option value="taskboard_csv" selected/', $html);
        // Since 1.26: Help on the left, Cancel and Import (bound to the form) on the right, outside the body.
        self::assertSame('nowo-task-subtask-import-form-' . $this->task->getId(), (new Crawler($html))->filter('dialog[data-dialog="subtask-import"] .nowo-task-dialog__footer .btn-primary')->attr('form'));
        $this->assertDialogAnatomy($html, 5);

        $readOnly = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['subtaskImportForm' => null, 'panel' => true]);
        self::assertStringNotContainsString('nowo-task-subtask-import', $readOnly);

        // Since 1.26.2: an editor without the import permission (no form) keeps the add-subtask form but no dialog.
        $editorNoImport = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['subtaskImportForm' => null, 'canEditTask' => true, 'panel' => true]);
        self::assertStringContainsString('nowo-task-detail__inline-add', $editorNoImport);
        self::assertStringNotContainsString('nowo-task-subtask-import', $editorNoImport);
        self::assertStringNotContainsString('data-dialog="subtask-import"', $editorNoImport);

        // A form without edit rights (never built by the controller) still shows no dialog.
        $viewerWithForm = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['subtaskImportForm' => $form, 'canEditTask' => false, 'panel' => true]);
        self::assertStringNotContainsString('data-dialog="subtask-import"', $viewerWithForm);
    }

    public function testBoardPageRenders(): void
    {
        $choices = ['Todo' => $this->todo->getId()];
        $html    = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'columnForm'     => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'editColumnForm' => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'taskForm'       => $this->factory()->create(TaskFormType::class, new TaskFormData(columnId: $this->todo->getId()), [
                'column_choices'   => $choices,
                'rich_description' => false,
                'tag_input'        => false,
            ])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('Ship it', $html);
    }

    public function testBoardAndTaskPagesRenderWithoutColumns(): void
    {
        $taskForm = $this->factory()->create(TaskFormType::class, new TaskFormData(), [
            'column_choices'   => [],
            'rich_description' => false,
            'tag_input'        => false,
        ])->createView();
        self::assertFalse(isset($taskForm['columnId']));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', $this->boardContext(['tasks' => [], 'taskForm' => $taskForm]));
        self::assertStringContainsString('data-nowo-task-board-add-task-modal-target="titleInput"', $html);
        self::assertStringNotContainsString('data-nowo-task-board-add-task-modal-target="columnField"', $html);

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', [
            'task'     => $this->task,
            'taskForm' => $this->factory()->create(TaskFormType::class, new TaskFormData(title: 'Ship it'), [
                'column_choices'   => [],
                'rich_description' => false,
                'tag_input'        => false,
            ])->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($this->task),
            'subtaskTotal'     => 0,
            'subtaskDone'      => 0,
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
        ] + $this->taskActionForms($this->task));
        self::assertStringContainsString('Ship it', $html);
    }

    public function testBoardShowsColumnIconsSetsAndDeleteWithoutInlineStyle(): void
    {
        $this->todo->setColor('#16a34a')->setIcon('tabler:inbox');
        $set   = (new ColumnSet('Kanban', $this->user))->addItem('Todo')->addItem('Done');
        $other = new ColumnSet('Shared');
        $icons = ['tabler:inbox', 'tabler:bug'];

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', $this->boardContext([
            'columnForm'           => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => $icons])->createView(),
            'editColumnForm'       => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_choices' => $icons])->createView(),
            'columnIcons'          => $icons,
            'columnDeleteForm'     => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'd'])->createView(),
            'panelTargetPath'      => '/tools/task-board/board/' . $this->board->getId(),
            'columnSets'           => [$set, $other],
            'columnSetForm'        => $this->factory()->create(ColumnSetFormType::class, new ColumnSetFormData())->createView(),
            'columnSetApplyForm'   => $this->factory()->create(ColumnSetApplyFormType::class, new ColumnSetApplyFormData(), ['column_set_choices' => ['Kanban' => $set->getId(), 'Shared' => $other->getId()]])->createView(),
            'columnSetDeleteForms' => [$set->getId() => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 's', 'action' => '/delete-set'])->createView()],
        ]));

        self::assertStringNotContainsString(' style="', $html);
        self::assertStringContainsString('nowo-task-column--colored', $html);
        self::assertStringContainsString('data-nowo-task-vars="{&quot;--nowo-task-column-color&quot;:&quot;#16a34a&quot;}"', $html);
        self::assertStringContainsString('<i class="ti ti-inbox" aria-hidden="true"></i>', $html);
        self::assertStringContainsString('data-column-icon="tabler:inbox"', $html);
        self::assertStringContainsString('<option value="tabler:bug">bug</option>', $html);
        self::assertStringContainsString('data-task-panel-url="/tools/task-board/task/' . $this->task->getId() . '/panel?_target_path=/tools/task-board/board/' . $this->board->getId() . '"', $html);
        self::assertStringContainsString('task_board.action.delete_column', $html);
        self::assertStringContainsString('COLUMN_ID&#x2F;delete', $html);
        self::assertStringContainsString('nowo-task-dialog nowo-task-dialog--native', $html);
        self::assertStringContainsString('/column-sets/apply', $html);
        self::assertStringContainsString('action="/delete-set"', $html);
        self::assertSame(1, substr_count($html, 'nowo-task-column-sets__delete'));
        self::assertStringContainsString('Todo, Done', $html);
        // Since 1.26 (dialog rule v2): add task, add / edit column, save set, add from set — header, body, footer.
        $this->assertDialogAnatomy($html, 5);
        // Edit column has one primary: Delete column is a secondary danger action in the footer, bound to the hidden
        // delete form (which keeps its confirm).
        $editColumn = (new Crawler($html))->filter('dialog[data-nowo-task-board-edit-column-modal-target="modal"]');
        $delete     = $editColumn->filter('.nowo-task-dialog__footer button[form="nowo-task-board-column-delete-form"]');
        self::assertSame('nowo-ui-btn nowo-ui-btn--danger btn btn-outline-danger nowo-task-btn-danger', $delete->attr('class'));
        self::assertSame('nowo-task-board-column-delete-help', $delete->attr('aria-describedby'));
        $deleteForm = $editColumn->filter('form#nowo-task-board-column-delete-form');
        self::assertSame('hidden', $deleteForm->attr('hidden'));
        self::assertNotEmpty($deleteForm->attr('data-confirm'));
        self::assertSame('nowo-task-board-edit-column-form', $editColumn->filter('.nowo-task-dialog__footer .nowo-ui-btn--primary')->attr('form'));
        self::assertCount(1, $editColumn->filter('#nowo-task-board-column-delete-help'));
        self::assertStringContainsString('nowo-task-dialog__actions--split', (string) $editColumn->filter('.nowo-task-dialog__footer')->attr('class'));
        self::assertStringNotContainsString('nowo-task-dialog__danger"', $html);
        // Add columns from a set: the apply submit is the footer's primary, after Cancel.
        $apply = (new Crawler($html))->filter('dialog[data-dialog="apply"] .nowo-task-dialog__footer button');
        self::assertCount(2, $apply);
        self::assertSame('nowo-task-column-set-apply-form', $apply->eq(1)->attr('form'));
    }

    public function testBoardColumnSetsDialogHasAnEmptyState(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', $this->boardContext([
            'columnSets'         => [],
            'columnSetForm'      => $this->factory()->create(ColumnSetFormType::class, new ColumnSetFormData())->createView(),
            'columnSetApplyForm' => $this->factory()->create(ColumnSetApplyFormType::class, new ColumnSetApplyFormData())->createView(),
        ]));

        self::assertStringContainsString('task_board.column_set.empty', $html);
        self::assertStringNotContainsString('/column-sets/apply', $html);
        // Without sets the dialog has nothing to apply: Close only (1.26).
        $footer = (new Crawler($html))->filter('dialog[data-dialog="apply"] .nowo-task-dialog__footer button');
        self::assertCount(1, $footer);
        self::assertNotNull($footer->attr('data-nowo-ui-dialog-close'));
        $this->assertDialogAnatomy($html, 5);
        // Without columnDeleteForm the edit column footer is not split.
        self::assertStringNotContainsString('--split', (string) (new Crawler($html))->filter('dialog[data-nowo-task-board-edit-column-modal-target="modal"] .nowo-task-dialog__footer')->attr('class'));
        // A two-column board without columnDeleteForm (older controllers) has no delete button.
        self::assertStringNotContainsString('task_board.action.delete_column', $html);
    }

    public function testColumnIconFollowsTheUiKitIconSet(): void
    {
        $this->todo->setIcon('tabler:inbox');

        $ux = $this->twig();
        $ux->addGlobal('nowo_ui_kit_icon_set', 'ux_icon');
        $ux->addFunction(new TwigFunction('ux_icon', static fn (string $name): string => '<svg data-icon="' . $name . '"></svg>', ['is_safe' => ['html']]));
        self::assertStringContainsString('<svg data-icon="tabler:inbox"></svg>', $ux->render('@NowoTaskBoardBundle/manage/_column_icon.html.twig', ['icon' => 'tabler:inbox']));

        $none = $this->twig();
        $none->addGlobal('nowo_ui_kit_icon_set', 'none');
        self::assertSame('', trim($none->render('@NowoTaskBoardBundle/manage/_column_icon.html.twig', ['icon' => 'tabler:inbox'])));
        self::assertSame('', trim($this->twig()->render('@NowoTaskBoardBundle/manage/_column_icon.html.twig', ['icon' => null])));
    }

    /** Since 2.9.0 (audit JI-L3): the dependency key is a CSS swatch; Bootstrap Icons get their own names, the Tabler font as before. */
    public function testTheGanttLegendNeedsNoIconAndBootstrapIconsGetTheirNames(): void
    {
        $context = ['board' => $this->board, 'dashboardRoute' => null, 'timeline' => (new TaskGanttBuilder())->build([$this->task])];
        $legend  = (new Crawler($this->twig()->render('@NowoTaskBoardBundle/manage/gantt.html.twig', $context)))->filter('.nowo-task-gantt__legend li');
        self::assertCount(4, $legend);
        self::assertCount(1, $legend->eq(0)->filter('span.nowo-task-gantt__legend-dependency[aria-hidden="true"]'));
        self::assertCount(0, $legend->filter('.nowo-task-icon'), 'no item depends on the icon set');

        $bootstrap = $this->twig();
        $bootstrap->addGlobal('nowo_ui_kit_icon_set', 'bootstrap-icons');
        self::assertStringContainsString('bi bi-floppy', $bootstrap->render('@NowoTaskBoardBundle/manage/_icon.html.twig', ['name' => 'device-floppy']));
        self::assertStringContainsString('bi bi-pencil', $bootstrap->render('@NowoTaskBoardBundle/manage/_icon.html.twig', ['name' => 'pencil']));
        self::assertStringContainsString('bi bi-check-circle', $bootstrap->render('@NowoTaskBoardBundle/manage/_column_icon.html.twig', ['icon' => 'tabler:circle-check']));
        self::assertStringContainsString('bi bi-circle-check', $bootstrap->render('@NowoTaskBoardBundle/manage/_column_icon.html.twig', ['icon' => 'lucide:circle-check']), 'a host id is left alone');
        $views = $bootstrap->render('@NowoTaskBoardBundle/manage/_board_item_views.html.twig', ['board' => $this->board]);
        self::assertStringContainsString('bi bi-kanban', $views);
        self::assertStringContainsString('bi bi-bar-chart', $views);
        self::assertStringContainsString('ti ti-device-floppy', $this->twig()->render('@NowoTaskBoardBundle/manage/_icon.html.twig', ['name' => 'device-floppy']), 'the Tabler font as before');
    }

    /** Since 2.9.0: an id renders byte for byte as before; any other value is aria-hidden, escaped text, whatever the icon set. */
    public function testAColumnIconThatIsNoIconIdIsPrintedAsHiddenText(): void
    {
        $partial = '@NowoTaskBoardBundle/manage/_column_icon.html.twig';
        self::assertSame(
            "    <span class=\"nowo-task-column__icon\" aria-hidden=\"true\">        <i class=\"ti ti-inbox\" aria-hidden=\"true\"></i>\n</span>\n",
            $this->twig()->render($partial, ['icon' => 'tabler:inbox']),
            'the output of 2.8.0',
        );
        self::assertSame(
            "    <span class=\"nowo-task-column__icon\" aria-hidden=\"true\">        <i class=\"ti ti-bug\" aria-hidden=\"true\"></i>\n</span>\n",
            $this->twig()->render($partial, ['icon' => 'bug']),
            'a bare name stored before 2.9.0 stays an icon',
        );
        self::assertSame("    <span class=\"nowo-task-column__icon nowo-task-column__icon--emoji\" aria-hidden=\"true\">🚧</span>\n", $this->twig()->render($partial, ['icon' => '🚧']));
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $this->twig()->render($partial, ['icon' => '<b>x</b>']), 'escaped, should one ever be stored');
        self::assertSame('', $this->twig()->render($partial, ['icon' => '']));

        $none = $this->twig();
        $none->addGlobal('nowo_ui_kit_icon_set', 'none');
        self::assertStringContainsString('aria-hidden="true">✅</span>', $none->render($partial, ['icon' => '✅']), 'an emoji needs no icon font');

        // The board: the emoji in the header next to the name, which stays the accessible text, and in the edit trigger.
        $this->todo->setIcon('📝');
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'taskTree'       => TaskTree::fromTasks([$this->task]),
            'columnForm'     => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_form_type' => TextType::class])->createView(),
            'editColumnForm' => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData(), ['icon_form_type' => TextType::class])->createView(),
            'taskForm'       => $this->factory()->create(TaskFormType::class, new TaskFormData(columnId: $this->todo->getId()), [
                'column_choices'   => ['Todo' => $this->todo->getId()],
                'rich_description' => false,
                'tag_input'        => false,
            ])->createView(),
            'dashboardRoute' => null,
        ]);
        self::assertStringContainsString('<span class="nowo-task-column__icon nowo-task-column__icon--emoji" aria-hidden="true">📝</span>', $html);
        self::assertStringContainsString('data-column-icon="📝"', $html);
        self::assertStringNotContainsString('nowo-task-picker__icons', $html, 'a host widget replaces the grid');
        self::assertStringContainsString('nowo-task-field--column-icon', $html);
        self::assertStringContainsString('task_board.form.column.icon_help', $html);
    }

    public function testTaskPanelIsAFragmentWhoseFormsComeBackToTheBoard(): void
    {
        $member = new TaskMember($this->task, $this->user, TaskMemberRole::Assignee);
        $this->task->addMember($member);
        $extra = ['_target_path' => '/tools/task-board/board/' . $this->board->getId()];

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', [
            'task'             => $this->task,
            'taskForm'         => $this->taskForm(TaskFormData::class, false)->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($this->task),
            'subtaskTotal'     => 2,
            'subtaskDone'      => 1,
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
            'panel'            => true,
            'panelTargetPath'  => $extra['_target_path'],
            'taskRouteExtra'   => $extra,
        ] + $this->taskActionForms($this->task));

        $query = '?_target_path=/tools/task-board/board/' . $this->board->getId();
        self::assertStringNotContainsString('<title>', $html);
        self::assertStringNotContainsString(' style="', $html);
        self::assertStringStartsWith('<div', trim($html));
        self::assertStringContainsString('class="nowo-task-panel-fragment"', $html);
        self::assertStringContainsString('action="/tools/task-board/task/' . $this->task->getId() . $query . '"', $html);
        self::assertStringContainsString('/tools/task-board/task/' . $this->task->getId() . '/subtask' . $query, $html);
        self::assertStringContainsString('/tools/task-board/task/' . $this->task->getId() . '/advance' . $query, $html);
        self::assertStringContainsString('/tools/task-board/task/' . $this->task->getId() . '/member' . $query, $html);
        self::assertStringContainsString('/tools/task-board/task/' . $this->task->getId() . '/link' . $query, $html);
        self::assertStringContainsString('task_board.task.open_page', $html);
        self::assertStringContainsString('<progress class="nowo-task-progress nowo-task-subtasks__progress" max="2" value="1"', $html);
    }

    public function testListAndGanttCarryTaskPanelUrlsWithoutInlineStyle(): void
    {
        $context = [
            'board'           => $this->board,
            'tasks'           => [$this->task],
            'priorities'      => ['high'],
            'columnNames'     => ['Todo'],
            'filterForm'      => Forms::createFormFactory()->createNamed('', TaskListFilterType::class, null, ['priorities' => ['high'], 'column_names' => ['Todo']])->createView(),
            'dashboardRoute'  => null,
            'panelTargetPath' => '/list',
            'timeline'        => (new TaskGanttBuilder())->build([$this->task]),
        ];
        $panelUrl = 'data-task-panel-url="/tools/task-board/task/' . $this->task->getId() . '/panel?_target_path=/list"';

        $list = $this->twig()->render('@NowoTaskBoardBundle/manage/list.html.twig', $context);
        self::assertStringContainsString($panelUrl, $list);
        self::assertStringNotContainsString(' style="', $list);

        $gantt = $this->twig()->render('@NowoTaskBoardBundle/manage/gantt.html.twig', $context);
        self::assertStringContainsString($panelUrl, $gantt);
        self::assertStringContainsString('data-nowo-task-vars="{&quot;--nowo-task-depth&quot;:0}"', $gantt);
        self::assertStringNotContainsString(' style="', $gantt);
    }

    /**
     * Since 1.15: dependencies (both directions, add dialog, remove buttons), time tracking and the deviation, and the
     * platform disclosure toggle on every collapsible.
     */
    public function testTaskDetailEditsDependenciesAndTime(): void
    {
        $task    = $this->task;
        $blocker = new Task($this->board, 'Design', $this->user, column: $this->todo);
        $blocked = new Task($this->board, 'Deploy', $this->user, column: $this->todo, parent: $task);
        $blocked->markCompleted();
        $task->getSubtasks()->add($blocked);
        $task->addOutgoingDependency(new TaskDependency($task, $blocked, TaskDependencyType::Blocks));
        $blocker->addOutgoingDependency(new TaskDependency($blocker, $task, TaskDependencyType::Duplicates));
        $pairing = new TaskTimeEntry($task, $this->user, 120, new DateTimeImmutable('2026-10-27'), 'Pairing');
        $pairing->markBillable(true);
        $task->addTimeEntry($pairing);
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::DependencyAdded, null, 'Deploy', 'blocks'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::TimeBillable, null, 'true', '2026-10-27'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::TimeRemoved, '95', null, '2026-10-26'));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($task) + [
            'dependencyForm'       => $this->factory()->create(TaskDependencyFormType::class, new TaskDependencyFormData(), ['task_choices' => ['Design' => $blocker->getId()]])->createView(),
            'dependencyRemoveForm' => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'd'])->createView(),
            'timeEntryForm'        => $this->factory()->create(TaskTimeEntryFormType::class, new TaskTimeEntryFormData(loggedAt: new DateTimeImmutable('2026-10-28'), billable: true))->createView(),
            'timeEntryRemoveForm'  => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 't'])->createView(),
            // Since 2.12.0: the billable switch of each entry.
            'billableTimeEntryIds'  => [$pairing->getId()],
            'timeEntryBillableForm' => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'b'])->createView(),
            'deviation'             => TaskDeviation::of($task),
            'subtaskDeviation'      => TaskDeviation::sum([$blocked]),
        ]);

        // Dependencies: the add dialog with its searchable picker, one remove button per row, both directions.
        self::assertStringContainsString('data-dialog="dependency"', $html);
        self::assertStringContainsString('data-controller="nowo-task-board-option-filter"', $html);
        self::assertStringContainsString('data-nowo-task-board-option-filter-target="select"', $html);
        self::assertStringContainsString('id="nowo-task-dependency-remove-form"', $html);
        self::assertStringContainsString('task_board.dependency.relation.blocks', $html);
        self::assertStringContainsString('task_board.dependency.relation.duplicated_by', $html);
        self::assertStringContainsString('formaction="/tools/task-board/task/' . $task->getId() . '/dependency/', $html);
        self::assertStringContainsString('nowo-task-dependencies__task is-done', $html);
        // Since 1.26: people, priority, links, dependency and time dialogs, each with its footer outside the body.
        $this->assertDialogAnatomy($html, 5);
        // Time: estimate 1 h 30 min, 2 h spent, +30 min (+33 %), over; the subtasks' row; the entries and the dialog.
        self::assertStringContainsString('nowo-task-deviation--over', $html);
        self::assertStringContainsString('<dd>1 h 30 min</dd>', $html);
        self::assertStringContainsString('+30 min', $html);
        self::assertStringContainsString('(+33 %)', $html);
        self::assertStringContainsString('task_board.time.subtasks_sum', $html);
        self::assertStringContainsString('Pairing', $html);
        self::assertStringContainsString('data-dialog="time"', $html);
        self::assertStringContainsString('name="task_time_entry[minutes]"', $html);
        self::assertStringContainsString('id="nowo-task-time-remove-form"', $html);
        // Since 2.12.0: the Billable switch (checked: the host default), the entry's badge and its switch button, the history row.
        self::assertMatchesRegularExpression('~<input type="checkbox" id="task_time_entry_billable" name="task_time_entry\[billable\]"[^>]*checked~', $html);
        self::assertStringContainsString('data-billable="1"', $html);
        self::assertStringContainsString('id="nowo-task-time-billable-form"', $html);
        self::assertStringContainsString('formaction="/tools/task-board/task/' . $task->getId() . '/time/' . $pairing->getId() . '/billable"', $html);
        self::assertStringContainsString('name="billable"' . "\n" . '                        value="0"', $html);
        self::assertStringContainsString('task_board.time.mark_not_billable', $html);
        self::assertStringContainsString('task_board.history.type.time_billable', $html);
        self::assertStringContainsString('<span class="nowo-task-activity__old">task_board.time.billable_unset</span>', $html);
        self::assertStringContainsString('nowo-task-deviation-pill--over', $html);
        self::assertStringContainsString('task_board.history.type.dependency_added', $html);
        self::assertStringContainsString('<span class="nowo-task-activity__old">1 h 35 min</span>', $html);
        // Every <summary> carries the disclosure toggle (UiKit partial, morph icon).
        self::assertSame(substr_count($html, '<summary'), substr_count($html, 'data-controller="disclosure-icon"'));
        self::assertGreaterThan(0, substr_count($html, 'nowo-task-disclosure'));
        self::assertStringContainsString('data-disclosure-morph', $html);
        self::assertStringNotContainsString(' style="', $html);
    }

    public function testReadOnlyTaskShowsDependenciesAndTimeWithoutWriteControls(): void
    {
        $other = new Task($this->board, 'Other', $this->user);
        $this->task->addOutgoingDependency(new TaskDependency($this->task, $other, TaskDependencyType::BlockedBy));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($this->task) + [
            'canEditTask'          => false,
            'dependencyForm'       => $this->factory()->create(TaskDependencyFormType::class, new TaskDependencyFormData())->createView(),
            'dependencyRemoveForm' => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'd'])->createView(),
        ]);

        self::assertStringContainsString('task_board.dependency.relation.blocked_by', $html);
        self::assertStringContainsString('task_board.time.status.on_track', $html);
        self::assertStringNotContainsString('id="nowo-task-dependency-remove-form"', $html);
        self::assertStringNotContainsString('data-dialog="dependency"', $html);
        self::assertStringNotContainsString('data-dialog="time"', $html);
    }

    public function testListFoldsItsFiltersAndShowsTheTimeColumns(): void
    {
        $this->task->addTimeEntry(new TaskTimeEntry($this->task, $this->user, 120, new DateTimeImmutable()));
        $plain = new Task($this->board, 'No estimate', $this->user, column: $this->todo);

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/list.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task, $plain],
            'priorities'     => ['high'],
            'columnNames'    => ['Todo'],
            'filterForm'     => Forms::createFormFactory()->createNamed('', TaskListFilterType::class, null, ['priorities' => ['high'], 'column_names' => ['Todo']])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('data-controller="nowo-task-board-filter-fold"', $html);
        self::assertStringNotContainsString('data-controller="filters-collapse"', $html);
        self::assertStringContainsString('data-nowo-task-board-list-filter-board-value="' . $this->board->getId() . '"', $html);
        self::assertStringContainsString('data-nowo-task-board-list-filter-target="overEstimate"', $html);
        self::assertMatchesRegularExpression('/name="sort"[^>]*data-nowo-task-board-list-filter-target="sort"|data-nowo-task-board-list-filter-target="sort"[^>]*name="sort"/', $html);
        self::assertMatchesRegularExpression('/data-due="2026-12-01"/', $html);
        self::assertMatchesRegularExpression('/data-nowo-task-board-list-filter-target="timeColumns"[^>]*data-nowo-task-filter-ignore/', $html);
        self::assertMatchesRegularExpression('/data-estimate="90"\s+data-spent="120"\s+data-over="1"/', $html);
        self::assertMatchesRegularExpression('/data-estimate=""\s+data-spent="0"\s+data-over="0"/', $html);
        self::assertStringContainsString('<tfoot class="nowo-ui-table__foot nowo-task-list__time-col" data-nowo-task-board-list-filter-target="totals">', $html);
        self::assertStringContainsString('nowo-task-list__totals', $html);
        self::assertStringContainsString('+30 min (+33 %)', $html);
        self::assertStringNotContainsString(' style="', $html);
    }

    public function testTheHostFoldsTheListFiltersWithItsOwnPartial(): void
    {
        $html = $this->twig(null, 'host/_filters_collapse.html.twig', 'issue-filters panel')->render('@NowoTaskBoardBundle/manage/list.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'priorities'     => ['high'],
            'columnNames'    => ['Todo'],
            'filterForm'     => Forms::createFormFactory()->createNamed('', TaskListFilterType::class, null, ['priorities' => ['high'], 'column_names' => ['Todo']])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringNotContainsString('nowo-task-board-filter-collapse', $html);
        self::assertStringNotContainsString('nowo-task-list__filter-toggle', $html);
        self::assertMatchesRegularExpression('/<form[^>]*class="nowo-ui-filter-bar nowo-task-board-list-filters nowo-task-list__filters issue-filters panel"[^>]*>\s*<div class="host-fold" data-key="nowo-task-board-list-' . $this->board->getId() . '" data-fold-key="nowo-task-board-list-' . $this->board->getId() . '"/', $html);
        self::assertStringContainsString('data-nowo-task-board-list-filter-target="overEstimate"', $html);
    }

    public function testGanttOffersTheScalesAndDrawsTheTimeSpent(): void
    {
        $this->task->addTimeEntry(new TaskTimeEntry($this->task, $this->user, 120, new DateTimeImmutable()));
        $long = new Task($this->board, 'Long', $this->user, dueAt: new DateTimeImmutable('+6 years'));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/gantt.html.twig', [
            'board'          => $this->board,
            'timeline'       => (new TaskGanttBuilder())->build([$this->task, $long], GanttScale::Months, WeekStart::Sunday),
            'scale'          => GanttScale::Months,
            'scales'         => GanttScale::cases(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('data-week-start="sunday"', $html);
        self::assertStringContainsString('nowo-task-gantt--months', $html);
        self::assertMatchesRegularExpression('/<a[^>]*href="[^"]*scale=months"[^>]*aria-current="[^"]*"/', $html);
        self::assertStringContainsString('data-action="nowo-task-board-gantt#rememberScale"', $html);
        self::assertStringContainsString('href="/tools/task-board/board/' . $this->board->getId() . '/gantt?scale=hours"', $html);
        self::assertStringContainsString('task_board.gantt.clamped_months', $html);
        self::assertStringContainsString('nowo-task-gantt__bar-spent', $html);
        self::assertStringContainsString('{&quot;--nowo-task-spent&quot;:100}', $html);
        self::assertStringContainsString('is-overrun', $html);
        self::assertStringContainsString('task_board.gantt.bar_time', $html);
        self::assertStringNotContainsString(' style="', $html);

        foreach ([GanttScale::Hours, GanttScale::Weeks, GanttScale::Days] as $scale) {
            $html = $this->twig()->render('@NowoTaskBoardBundle/manage/gantt.html.twig', [
                'board'          => $this->board,
                'timeline'       => (new TaskGanttBuilder())->build([$this->task], $scale),
                'scales'         => GanttScale::cases(),
                'dashboardRoute' => null,
            ]);
            self::assertStringContainsString('data-scale="' . $scale->value . '"', $html);
        }
    }

    /**
     * Since 1.15: a subtask opens with its parent path; the start date sits next to the due date; titles are limited.
     */
    public function testSubtaskDetailShowsItsParentsAndTheStartDate(): void
    {
        $story = new Task($this->board, 'Story', $this->user, parent: $this->task);
        $leaf  = new Task($this->board, 'Leaf', $this->user, parent: $story);

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($leaf) + ['panel' => true, 'panelTargetPath' => '/board', 'taskRouteExtra' => ['_target_path' => '/board']]);

        self::assertMatchesRegularExpression('/<nav class="nowo-task-detail__parents"[^>]*>\s*<ol[^>]*>\s*<li><a href="\/tools\/task-board\/task\/' . $this->task->getId() . '" data-task-panel-url="[^"]+">Ship it<\/a><\/li>\s*<li><a [^>]*>Story<\/a><\/li>\s*<li aria-current="page">Leaf<\/li>/', $html);
        self::assertStringContainsString('name="task_form[startAt]"', $html);
        self::assertMatchesRegularExpression('/name="task_form\[title\]"[^>]*maxlength="255"|maxlength="255"[^>]*name="task_form\[title\]"/', $html);

        $page = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($this->task));
        self::assertStringNotContainsString('nowo-task-detail__parents', $page);
    }

    /**
     * Since 1.25.1: the task page (not the panel fragment, which has its own hook) is wrapped in a stable
     * .nowo-task-page element carrying the task id, for integrations such as the TimeTrack browser extension.
     */
    public function testTheTaskPageHasAStableHookForIntegrations(): void
    {
        $page = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($this->task));
        self::assertSame(1, substr_count($page, 'class="nowo-task-page"'));
        self::assertStringContainsString('<div class="nowo-task-page" data-task-id="' . $this->task->getId() . '">', $page);

        $panel = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($this->task) + ['panel' => true, 'panelTargetPath' => '/board', 'taskRouteExtra' => ['_target_path' => '/board']]);
        self::assertStringNotContainsString('nowo-task-page', $panel);
        self::assertStringContainsString('data-task-id="' . $this->task->getId() . '"', $panel);
    }

    public function testLongTitlesAndAStartAfterTheDueDateAreFormErrors(): void
    {
        $form = $this->factory()->create(TaskFormType::class, new TaskFormData(), ['column_choices' => ['Todo' => $this->todo->getId()], 'rich_description' => false, 'tag_input' => false]);
        $form->submit(['title' => str_repeat('x', 256), 'priority' => 'normal', 'startAt' => '2026-11-10', 'dueAt' => '2026-11-01']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('title')->getErrors());
        self::assertSame('task_board.form.task.start_after_due', $form->get('startAt')->getErrors()[0]->getMessage());

        $ok = $this->factory()->create(TaskFormType::class, new TaskFormData(), ['column_choices' => ['Todo' => $this->todo->getId()], 'rich_description' => false, 'tag_input' => false]);
        $ok->submit(['title' => str_repeat('x', 255), 'priority' => 'normal', 'startAt' => '2026-11-01', 'dueAt' => '2026-11-10']);
        self::assertTrue($ok->isValid(), (string) $ok->getErrors(true));
    }

    /**
     * Since 1.16: comments interleaved with the history, the "Comments / All activity" toggle, mention chips (the
     * viewer's highlighted), the comment box as a combobox, the edit box of the viewer's comment and the counts.
     */
    public function testTaskActivityShowsCommentsAndTheCommentBox(): void
    {
        $task = $this->task;
        $bob  = new TestUser('2', 'bob', 'bob@example.com');
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::Priority, 'normal', 'high'));
        $mine = new TaskComment($task, $this->user, 'Ping @bob@example.com <script>', [['id' => '2', 'label' => 'bob@example.com']]);
        $hers = new TaskComment($task, $bob, 'Thanks @owner@example.com', [['id' => '1', 'label' => 'owner@example.com']]);
        $gone = (new TaskComment($task, $bob, 'gone'))->markDeleted();
        $edit = $this->factory()->create(TaskCommentFormType::class, new TaskCommentFormData('Ping @bob@example.com', '[{"id":"2","label":"bob@example.com"}]'));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($task) + [
            'comments'          => [$mine, $hers, $gone],
            'commentForm'       => $this->factory()->create(TaskCommentFormType::class, new TaskCommentFormData())->createView(),
            'commentEditForms'  => [$mine->getId() => $edit->createView()],
            'commentDeletable'  => [$mine->getId() => true, $hers->getId() => false],
            'commentDeleteForm' => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'c'])->createView(),
            'mentionCandidates' => [['id' => '2', 'label' => 'bob@example.com']],
            'viewerId'          => '1',
        ]);

        self::assertStringContainsString('data-controller="nowo-task-board-activity-filter"', $html);
        self::assertStringContainsString('data-filter="comments"', $html);
        self::assertStringContainsString('<span class="nowo-task-mention" data-user-id="2">@bob@example.com</span> &lt;script&gt;', $html);
        self::assertStringContainsString('nowo-task-mention nowo-task-mention--me', $html);
        self::assertMatchesRegularExpression('/class="nowo-task-activity__item nowo-task-comment is-mentioning-me"[^>]*data-comment-id="' . $hers->getId() . '"/', $html);
        self::assertStringContainsString('task_board.comment.deleted', $html);
        self::assertStringContainsString('id="nowo-task-comment-delete-form"', $html);
        self::assertStringContainsString('formaction="/tools/task-board/task/' . $task->getId() . '/comment/' . $mine->getId() . '/delete"', $html);
        self::assertStringNotContainsString('/comment/' . $hers->getId() . '/delete', $html);
        self::assertStringContainsString('id="nowo-task-comment-edit-' . $mine->getId() . '"', $html);
        self::assertMatchesRegularExpression('/role="combobox"[^>]*aria-autocomplete="list"[^>]*aria-expanded="false"[^>]*aria-controls="nowo-task-mention-list-task_comment"/', $html);
        self::assertStringContainsString('role="listbox"', $html);
        self::assertStringContainsString('data-nowo-task-board-mention-candidates-value="[{&quot;id&quot;:&quot;2&quot;,&quot;label&quot;:&quot;bob@example.com&quot;}]"', $html);
        // The stream is in time order: the history entry (added first) before the comments.
        self::assertLessThan(strpos($html, 'data-comment-id="' . $mine->getId() . '"'), strpos($html, 'task_board.history.type.priority'));
        self::assertSame(substr_count($html, '<summary'), substr_count($html, 'data-controller="disclosure-icon"'));
        self::assertStringNotContainsString(' style="', $html);

        $board = $this->twig()->render('@NowoTaskBoardBundle/manage/board.html.twig', $this->boardContext(['commentCounts' => [$task->getId() => 2]]));
        self::assertStringContainsString('nowo-task-card__comments', $board);
        $readOnly = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($task) + ['comments' => [$hers], 'commentForm' => null, 'viewerId' => '3']);
        self::assertStringNotContainsString('role="combobox"', $readOnly);
        self::assertStringContainsString('data-filter="comments"', $readOnly);
    }

    public function testTheTaskPageStepsThroughTheBoardAndTheSubtasks(): void
    {
        $done   = $this->board->getColumns()->last();
        $next   = new Task($this->board, 'Next card', $this->user, column: $done);
        $first  = new Task($this->board, 'First sub', $this->user, column: $this->todo, parent: $this->task);
        $second = new Task($this->board, 'Second sub', $this->user, column: $done, parent: $this->task);
        $tasks  = [$this->task, $first, $second, $next];
        $tree   = TaskTree::fromTasks($tasks);

        // The first card of the board: previous disabled, next jumps to the next column, the way into its subtasks.
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($this->task) + [
            'taskTree'       => $tree,
            'taskNavigation' => TaskNavigator::navigate($this->task, $tasks, $tree, null, true),
        ]);
        self::assertStringContainsString('data-controller="nowo-task-board-task-nav"', $html);
        self::assertStringContainsString('data-nowo-task-board-task-nav-keys-value="true"', $html);
        self::assertStringContainsString('<span class="nowo-task-nav__position" aria-live="polite" data-nowo-task-nav-position>task_board.nav.position_column</span>', $html);
        self::assertMatchesRegularExpression('/<span class="nowo-task-nav__step nowo-task-nav__step--previous is-disabled" aria-disabled="true"/', $html);
        self::assertMatchesRegularExpression('/<a\s+class="nowo-task-nav__step nowo-task-nav__step--next"\s+href="\/tools\/task-board\/task\/' . $next->getId() . '"\s+data-nowo-task-board-task-nav-target="next"/', $html);
        self::assertMatchesRegularExpression('/class="nowo-task-nav__first-subtask"\s+href="\/tools\/task-board\/task\/' . $first->getId() . '"/', $html);
        // The subtask rows: title on the left, status badge on the right, no host badge classes.
        self::assertStringContainsString('<span class="nowo-task-subtree__column nowo-task-badge"', $html);
        self::assertStringNotContainsString('bg-secondary-lt', $html);
        // The time left, not a signed deviation.
        self::assertStringContainsString('nowo-task-remaining--left', $html);
        self::assertStringContainsString('task_board.time.left', $html);
        // The estimate is a duration.
        self::assertMatchesRegularExpression('/name="task_form\[estimatedMinutes\]"[^>]*value="1 h 30 min"|value="1 h 30 min"[^>]*name="task_form\[estimatedMinutes\]"/', $html);

        // The list order keeps `from=list` on its links.
        $list = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($first) + [
            'taskTree'       => $tree,
            'taskNavigation' => TaskNavigator::navigate($first, $tasks, $tree, 'list', true),
        ]);
        self::assertStringContainsString('/tools/task-board/task/' . $second->getId() . '?from=list', $list);
        self::assertStringContainsString('task_board.nav.subtask_position', $list);
        self::assertMatchesRegularExpression('/class="nowo-task-nav__parent"\s+href="\/tools\/task-board\/task\/' . $this->task->getId() . '\?from=list"/', $list);

        // A subtask of a board of top-level cards: its siblings take the keys; in the panel only the subtask steps
        // show, opening in the drawer, without keys.
        $nav  = TaskNavigator::navigate($second, $tasks, $tree, null, true);
        $page = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($second) + ['taskTree' => $tree, 'taskNavigation' => $nav]);
        self::assertStringNotContainsString('task_board.nav.position_column', $page);
        self::assertMatchesRegularExpression('/data-nowo-task-board-task-nav-target="previous"\s+data-action="nowo-task-board-task-nav#leave"\s+data-title="First sub"/', $page);
        $panel = $this->twig()->render('@NowoTaskBoardBundle/manage/task_panel.html.twig', $this->taskContext($second) + [
            'taskTree'        => $tree,
            'taskNavigation'  => TaskNavigator::navigate($second, $tasks, $tree, null, false),
            'panel'           => true,
            'panelTargetPath' => '/board',
            'taskRouteExtra'  => ['_target_path' => '/board'],
        ]);
        self::assertStringContainsString('nowo-task-nav nowo-task-nav--panel', $panel);
        // Since 2.5.0 (TB-11b): the public hooks next to the classes.
        $panelNav = (new Crawler($panel))->filter('nav[data-nowo-task-nav-context="panel"]');
        self::assertCount(1, $panelNav->filter('[data-nowo-task-nav="subtasks"]'));
        self::assertCount(0, $panelNav->filter('[data-nowo-task-nav="view"]'));
        self::assertCount(1, $panelNav->filter('[data-nowo-task-nav="subtasks"] [data-nowo-task-nav-position]'));
        self::assertCount(1, (new Crawler($panel))->filter('form#nowo-task-edit-form[data-nowo-task-edit-form]'));
        self::assertCount(1, (new Crawler($html))->filter('nav[data-nowo-task-nav-context="page"] [data-nowo-task-nav="view"]'));
        self::assertStringContainsString('data-nowo-task-board-task-nav-keys-value="false"', $panel);
        self::assertStringNotContainsString('task_board.nav.position_column', $panel);
        self::assertStringContainsString('data-task-panel-url="/tools/task-board/task/' . $first->getId() . '/panel?_target_path=/board"', $panel);
    }

    public function testBoardCardsCarryTheirTitleColumnAndAReadableEstimate(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/_task_card.html.twig', ['task' => $this->task]);

        self::assertStringContainsString('data-title="Ship it"', $html);
        self::assertStringContainsString('data-column-name="Todo"', $html);
        self::assertStringContainsString('1 h 30 min</span>', $html);
        self::assertStringNotContainsString('90m', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskContext(Task $task): array
    {
        return [
            'task'             => $task,
            'taskForm'         => $this->taskForm(TaskFormData::class, false)->createView(),
            'linkForm'         => $this->factory()->create(TaskLinkFormType::class, new TaskLinkFormData())->createView(),
            'memberForm'       => $this->memberForm(),
            'subtaskForm'      => $this->taskForm(TaskFormData::class, true)->createView(),
            'columnNavigation' => (new TaskManager($this->createMock(TaskRepositoryInterface::class), new TaskChangeRecorder()))->getColumnNavigation($task),
            'priorities'       => TaskPriority::cases(),
            'dashboardRoute'   => null,
        ] + $this->taskActionForms($task);
    }

    public function testIndexOffersTheColumnSetsForANewBoard(): void
    {
        $set  = new ColumnSet('Kanban');
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/index.html.twig', [
            'page'           => new ListPage(new PageHeader('task_board.index.title'), [new Column('name')], [], empty: new EmptyState('task_board.index.no_boards'), bundle: TaskBoardPages::BUNDLE, layout: TaskBoardPages::LEGACY_ADMIN_BRIDGE),
            'canCreateBoard' => true,
            'boards'         => [],
            'boardForm'      => $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData(), ['column_set_choices' => ['Kanban' => $set->getId()]])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('name="task_board_form[columnSetId]"', $html);
        self::assertStringContainsString('task_board.form.board.default_columns', $html);
        self::assertStringContainsString('task_board.index.no_boards', $html);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function boardContext(array $extra): array
    {
        return $extra + [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'columnForm'     => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'editColumnForm' => $this->factory()->create(BoardColumnFormType::class, new BoardColumnFormData())->createView(),
            'taskForm'       => $this->factory()->create(TaskFormType::class, new TaskFormData(columnId: $this->todo->getId()), [
                'column_choices'   => ['Todo' => $this->todo->getId()],
                'rich_description' => false,
                'tag_input'        => false,
            ])->createView(),
            'dashboardRoute' => null,
        ];
    }

    public function testImportPageRenders(): void
    {
        $importForm = $this->factory()->create(TaskImportFormType::class, new TaskImportFormData())->createView();
        $html       = $this->twig()->render('@NowoTaskBoardBundle/manage/import.html.twig', [
            'page'           => new FormPage(new PageHeader('Roadmap'), $importForm, bundle: TaskBoardPages::BUNDLE, layout: TaskBoardPages::LEGACY_ADMIN_BRIDGE),
            'board'          => $this->board,
            'importForm'     => $importForm,
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('Roadmap', $html);
        // Since 1.18: the help modal says what each format imports, with the own format's columns and examples.
        self::assertStringContainsString('data-nowo-ui-dialog-open="nowo-task-import-help-board-' . $this->board->getId() . '"', $html);
        self::assertStringContainsString('task_board.import.help.intro_board', $html);
        self::assertStringContainsString('task_board.import.help.col_depends_on', $html);
        self::assertStringContainsString('task_board.import.help.source_trello_json', $html);
        self::assertStringContainsString('id,parent,title,status,start,due,estimate,depends_on', $html);
        self::assertStringContainsString('value="taskboard_csv"', $html);
        $this->assertDialogAnatomy($html, 1);
    }

    /**
     * @param class-string<TaskFormData> $dataClass
     *
     * @return FormInterface<TaskFormData>
     */
    private function taskForm(string $dataClass, bool $compact): FormInterface
    {
        $data = $compact ? new $dataClass() : new $dataClass(
            title: $this->task->getTitle(),
            description: $this->task->getDescription(),
            priority: $this->task->getPriority(),
            columnId: $this->task->getColumn()?->getId(),
            estimatedMinutes: $this->task->getEstimatedMinutes(),
            dueAt: $this->task->getDueAt(),
            tags: $this->task->getTags(),
            color: $this->task->getColor(),
        );

        return $this->factory()->create(TaskFormType::class, $data, [
            'column_choices'   => ['Todo' => $this->todo->getId()],
            'compact'          => $compact,
            'rich_description' => false,
            'tag_input'        => false,
        ]);
    }

    /**
     * Form views the controller always passes to manage/task.html.twig (REQ-TWIG-005).
     *
     * @return array<string, mixed>
     */
    private function taskActionForms(Task $task): array
    {
        $memberRemoveForms = [];
        foreach ($task->getMembers() as $member) {
            $memberRemoveForms[$member->getId()] = $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'm' . $member->getId()])->createView();
        }

        return [
            'advanceForm'       => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'a'])->createView(),
            'linkRemoveForm'    => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'l'])->createView(),
            'priorityForm'      => $this->factory()->createNamed('', TaskPriorityFormType::class, ['priority' => $task->getPriority()->value])->createView(),
            'memberRemoveForms' => $memberRemoveForms,
        ];
    }

    /**
     * Stand-in for TaskMemberFormType, whose user field is a Doctrine EntityType.
     */
    private function memberForm(): FormView
    {
        return $this->factory()->createNamedBuilder('task_member_form', FormType::class)
            ->add('user', ChoiceType::class, ['choices' => ['owner@example.com' => '1']])
            ->add('memberRole', EnumType::class, ['class' => TaskMemberRole::class])
            ->getForm()
            ->createView();
    }

    private function factory(): FormFactoryInterface
    {
        $profile = [
            'translation_domain' => 'NowoTaskBoardBundle',
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
        $merger = new FormOptionsMerger(['default' => $profile, 'task_board' => $profile], 'default', new ConstraintDefinitionFactory());
        $types  = [new TaskFormType(), new TaskLinkFormType(), new BoardColumnFormType(), new TaskBoardFormType(), new TaskImportFormType(), new ColumnSetFormType(), new ColumnSetApplyFormType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            // Since 2.12.0: FormKit's `switch` checkbox option (the Log time dialog's Billable switch).
            ->addTypeExtension(new SwitchExtension())
            ->addTypes($types)
            ->getFormFactory();
    }

    /**
     * Since 1.26 (dialog rule v2): every native dialog has the header (title, ×), a body, and the footer outside the
     * body as its last child; at most one primary, the last button; a footer submit names a form of its dialog.
     */
    private function assertDialogAnatomy(string $html, int $expected): void
    {
        $dialogs = (new Crawler($html))->filter('dialog.nowo-task-dialog--native');
        self::assertCount($expected, $dialogs);

        foreach ($dialogs as $dialog) {
            $children = [];
            foreach ($dialog->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $children[] = $child;
                }
            }
            self::assertGreaterThanOrEqual(3, count($children));
            $header = new Crawler($children[0]);
            $footer = new Crawler($children[count($children) - 1]);
            self::assertStringContainsString('nowo-ui-dialog__header', (string) $header->attr('class'));
            self::assertCount(1, $header->filter('.nowo-ui-dialog__title'));
            self::assertCount(1, $header->filter('button.nowo-ui-dialog__close'));
            self::assertCount(1, (new Crawler($dialog))->filter('.nowo-task-dialog__body'));
            self::assertStringContainsString('nowo-task-dialog__footer', (string) $footer->attr('class'));

            $buttons   = $footer->filter('button');
            $primaries = $footer->filter('.nowo-ui-btn--primary, .nowo-ui-btn--danger-solid');
            self::assertGreaterThanOrEqual(1, $buttons->count());
            self::assertLessThanOrEqual(1, $primaries->count());
            if ($primaries->count() === 1) {
                self::assertSame($buttons->last()->getNode(0), $primaries->getNode(0));
            }
            foreach ($buttons as $button) {
                self::assertInstanceOf(DOMElement::class, $button);
                if ($button->getAttribute('type') === 'submit') {
                    self::assertCount(1, (new Crawler($dialog))->filter('form#' . $button->getAttribute('form')));
                }
            }
        }
    }

    private function twig(?TaskObjectLinkResolver $linkResolver = null, ?string $filtersCollapse = null, string $filtersFormClass = ''): Environment
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['user_class' => TestUser::class]]);
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes     = $config['routes'];
        $collection = (new TaskBoardRouteLoader($routes, ''))->load('.', 'nowo_task_board');

        $filesystem = new FilesystemLoader([self::VENDOR . '/symfony/twig-bridge/Resources/views/Form']);
        $filesystem->addPath(self::VIEWS, 'NowoTaskBoardBundle');
        $filesystem->addPath(self::VENDOR . '/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKitBundle');

        $loader = new ChainLoader([
            new ArrayLoader([
                'host/_filters_collapse.html.twig' => '<div class="host-fold" data-key="{{ key }}" data-fold-key="{{ fold_key }}" data-fields="{{ form|length }}"></div>',
                'layout.html.twig'                 => '<title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}{% block javascripts %}{% endblock %}<main class="{% block container_class %}{% endblock %}">{% block body %}{% endblock %}</main>',
            ]),
            $filesystem,
        ]);

        $twig   = new Environment($loader, ['strict_variables' => true, 'debug' => true]);
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TaskBoardTwigExtension($routes, $linkResolver ?? new TaskObjectLinkResolver(), $filtersCollapse, $filtersFormClass, pages: new TaskBoardPages('layout.html.twig'), urls: new UrlGenerator($collection, new RequestContext())));
        $twig->addExtension(new ThemeExtension(new Theme()));
        // The task page's scaffold reads it; the pages with a model of their own pass theirs.
        $twig->addGlobal('page', new ShowPage(new PageHeader('Task'), bundle: TaskBoardPages::BUNDLE, layout: TaskBoardPages::LEGACY_ADMIN_BRIDGE));
        $twig->addExtension(new RoutingExtension(new UrlGenerator($collection, new RequestContext())));
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $twig->addFunction(new TwigFunction('asset', static fn (string $path, ?string $package = null): string => '/assets/' . $path));
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('success', 'task_board.flash.task_updated');
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $app = new AppVariable();
        $app->setRequestStack($requestStack);
        $twig->addGlobal('app', $app);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token-' . $id));

        return $twig;
    }
}
