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
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
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
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskDependencyType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Form\BoardColumnFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetApplyFormType;
use Nowo\TaskBoardBundle\Form\ColumnSetFormType;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Form\TaskPriorityFormType;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskManager;
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
        self::assertStringContainsString('<span class="text-decoration-line-through">legacy</span>', $html);
        self::assertStringContainsString('<span>api, ui</span>', $html);
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
        self::assertStringContainsString('<a href="/projects/1/issues/12" class="fw-semibold text-truncate nowo-task-link__label">BEACON-12 Crash</a>', $html);
        self::assertStringContainsString('bg-success-lt nowo-task-link__badge">Resolved', $html);
        self::assertStringContainsString('<span class="fw-semibold text-truncate nowo-task-link__label">Order 7</span>', $html);
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
        self::assertStringContainsString('<progress class="nowo-task-progress mt-2" max="2" value="1"', $html);
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
