<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Dto\TaskBoardFormData;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Dto\TaskImportFormData;
use Nowo\TaskBoardBundle\Dto\TaskLinkFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
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
use Nowo\TaskBoardBundle\Form\TaskBoardFormType;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Form\TaskImportFormType;
use Nowo\TaskBoardBundle\Form\TaskLinkFormType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskManager;
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
        ]);

        self::assertStringContainsString('<strong>27 min</strong>', $html);
        self::assertStringContainsString('Ship it', $html);
        self::assertStringContainsString('Write docs', $html);
        self::assertStringContainsString('Proofread', $html);
        self::assertStringContainsString('MR !1', $html);
        self::assertStringContainsString('Spec', $html);
        self::assertStringContainsString('<span class="text-decoration-line-through">legacy</span>', $html);
        self::assertStringContainsString('<span>api, ui</span>', $html);
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
        ]);

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
        $types  = [new TaskFormType(), new TaskLinkFormType(), new BoardColumnFormType(), new TaskBoardFormType(), new TaskImportFormType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypes($types)
            ->getFormFactory();
    }

    private function twig(): Environment
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
        $twig->addExtension(new TaskBoardTwigExtension('layout.html.twig', 'tabler', $routes));
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
