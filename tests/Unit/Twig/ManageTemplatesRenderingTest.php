<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetApplyFormData;
use Nowo\TaskBoardBundle\Dto\ColumnSetFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
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
use Nowo\TaskBoardBundle\Form\TaskDependencyFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Form\TaskPriorityFormType;
use Nowo\TaskBoardBundle\Form\TaskTimeEntryFormType;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Support\TaskTree;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
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
        self::assertStringContainsString('class="nowo-task-members__add"', $html);
        self::assertStringContainsString('data-action="change-&gt;nowo-task-board-task-priority-modal#select"', $html);
        self::assertStringNotContainsString('class="modal', $html);
        self::assertStringNotContainsString('modal-backdrop', $html);
        self::assertStringNotContainsString(' style="', $html);
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
        self::assertStringContainsString('class="nowo-task-dialog nowo-task-dialog--native nowo-task-dialog--wide"', $board);
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
        self::assertStringContainsString('data-depth="3"', $html);
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

    public function testPageHeaderActionsAreEscaped(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/_page_header.html.twig', [
            'pageTitle'     => 'Board',
            'headerActions' => [['href' => '/x?a=1&b="2"', 'label' => '<b>List</b>', 'icon' => 'list']],
        ]);

        self::assertStringContainsString('href="/x?a=1&amp;b=&quot;2&quot;"', $html);
        self::assertStringContainsString('&lt;b&gt;List&lt;/b&gt;', $html);
        self::assertStringContainsString('class="btn btn-outline-primary"', $html);
        self::assertStringNotContainsString('<b>List</b>', $html);

        $legacy = $this->twig()->render('@NowoTaskBoardBundle/manage/_page_header.html.twig', [
            'pageTitle'     => 'Board',
            'headerActions' => '<a href="/x">Old</a>',
        ]);
        self::assertStringContainsString('&lt;a href=&quot;/x&quot;&gt;Old&lt;/a&gt;', $legacy);
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
            'boards'         => [$this->board],
            'boardForm'      => $this->factory()->create(TaskBoardFormType::class, new TaskBoardFormData())->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('Roadmap', $html);
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
        self::assertStringContainsString('<dialog class="nowo-task-dialog nowo-task-dialog--native"', $html);
        self::assertStringContainsString('/column-sets/apply', $html);
        self::assertStringContainsString('action="/delete-set"', $html);
        self::assertSame(1, substr_count($html, 'nowo-task-column-sets__delete'));
        self::assertStringContainsString('Todo, Done', $html);
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
        self::assertStringContainsString('<progress class="nowo-task-progress" max="2" value="1"', $html);
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
        $task->addTimeEntry(new TaskTimeEntry($task, $this->user, 120, new DateTimeImmutable('2026-10-27'), 'Pairing'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::DependencyAdded, null, 'Deploy', 'blocks'));
        $task->addChangeHistory(new TaskChangeHistory($task, $this->user, TaskChangeType::TimeRemoved, '95', null, '2026-10-26'));

        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/task.html.twig', $this->taskContext($task) + [
            'dependencyForm'       => $this->factory()->create(TaskDependencyFormType::class, new TaskDependencyFormData(), ['task_choices' => ['Design' => $blocker->getId()]])->createView(),
            'dependencyRemoveForm' => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 'd'])->createView(),
            'timeEntryForm'        => $this->factory()->create(TaskTimeEntryFormType::class, new TaskTimeEntryFormData(loggedAt: new DateTimeImmutable('2026-10-28')))->createView(),
            'timeEntryRemoveForm'  => $this->factory()->createNamed('', CsrfActionType::class, null, ['csrf_token_id' => 't'])->createView(),
            'deviation'            => TaskDeviation::of($task),
            'subtaskDeviation'     => TaskDeviation::sum([$blocked]),
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

        self::assertStringContainsString('data-controller="nowo-task-board-filter-collapse"', $html);
        self::assertMatchesRegularExpression('/class="nowo-task-list__filter-toggle"[^>]*data-controller="disclosure-icon"[^>]*aria-expanded="true"[^>]*aria-controls="nowo-task-list-filters-/', $html);
        self::assertStringContainsString('data-nowo-task-board-list-filter-board-value="' . $this->board->getId() . '"', $html);
        self::assertStringContainsString('data-nowo-task-board-list-filter-target="overEstimate"', $html);
        self::assertMatchesRegularExpression('/name="sort"[^>]*data-nowo-task-board-list-filter-target="sort"|data-nowo-task-board-list-filter-target="sort"[^>]*name="sort"/', $html);
        self::assertMatchesRegularExpression('/data-due="2026-12-01"/', $html);
        self::assertMatchesRegularExpression('/data-nowo-task-board-list-filter-target="timeColumns"[^>]*data-nowo-task-filter-ignore/', $html);
        self::assertMatchesRegularExpression('/data-estimate="90"\s+data-spent="120"\s+data-over="1"/', $html);
        self::assertMatchesRegularExpression('/data-estimate=""\s+data-spent="0"\s+data-over="0"/', $html);
        self::assertStringContainsString('<tfoot class="nowo-task-list__time-col" data-nowo-task-board-list-filter-target="totals">', $html);
        self::assertStringContainsString('nowo-task-list__totals', $html);
        self::assertStringContainsString('+30 min (+33 %)', $html);
        self::assertStringNotContainsString(' style="', $html);
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
        self::assertMatchesRegularExpression('/href="[^"]*scale=months"[^>]*class="nowo-task-gantt__scale is-active"[^>]*aria-current="true"/', $html);
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
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/import.html.twig', [
            'board'          => $this->board,
            'importForm'     => $this->factory()->create(TaskImportFormType::class, new TaskImportFormData())->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('Roadmap', $html);
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
            ->addTypes($types)
            ->getFormFactory();
    }

    private function twig(?TaskObjectLinkResolver $linkResolver = null): Environment
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
                'layout.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}{% block javascripts %}{% endblock %}<main class="{% block container_class %}{% endblock %}">{% block body %}{% endblock %}</main>',
            ]),
            $filesystem,
        ]);

        $twig   = new Environment($loader, ['strict_variables' => true, 'debug' => true]);
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TaskBoardTwigExtension('layout.html.twig', 'tabler', $routes, $linkResolver ?? new TaskObjectLinkResolver()));
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
