<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\TaskListFilterType;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Service\TaskChangeRecorder;
use Nowo\TaskBoardBundle\Service\TaskGanttBuilder;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * Manage templates must link through nowo_task_board_route() so hosts can rename routes.*.name.
 */
final class RenamedRoutesRenderingTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../src/Resources/views';

    private const VENDOR = __DIR__ . '/../../../vendor';

    private const ROUTE_KEYS = [
        'index', 'board', 'list', 'gantt', 'task', 'board_create', 'task_create', 'task_move', 'task_advance',
        'task_link', 'task_link_update', 'task_link_remove', 'task_member', 'task_member_remove', 'task_subtask',
        'task_priority', 'column_create', 'column_update', 'column_reorder', 'board_import',
        'column_delete', 'column_set_create', 'column_set_apply', 'column_set_delete', 'task_panel',
    ];

    private TaskBoard $board;

    private BoardColumn $todo;

    private Task $task;

    protected function setUp(): void
    {
        $user        = new TestUser('1', 'owner@example.com');
        $this->board = new TaskBoard('Roadmap', 'roadmap', $user);
        $this->todo  = new BoardColumn($this->board, 'Todo', 0);
        $done        = new BoardColumn($this->board, 'Done', 1);
        $this->board->addColumn($this->todo)->addColumn($done);
        $this->task = new Task($this->board, 'Ship it', $user, column: $this->todo);
    }

    public function testListViewUsesConfiguredRouteNames(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/list.html.twig', [
            'board'          => $this->board,
            'tasks'          => [$this->task],
            'priorities'     => ['normal'],
            'columnNames'    => ['Todo'],
            'filterForm'     => Forms::createFormFactory()->createNamed('', TaskListFilterType::class, null, ['priorities' => ['normal'], 'column_names' => ['Todo']])->createView(),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('/host/board/' . $this->board->getId() . '/gantt', $html);
        self::assertStringContainsString('data-nowo-task-board-list-filter-target="priority"', $html);
        self::assertStringContainsString('class="nowo-task-board-list-filters nowo-task-list__filters"', $html);
        self::assertStringContainsString('/host/task/' . $this->task->getId(), $html);
    }

    public function testGanttViewUsesConfiguredRouteNames(): void
    {
        $html = $this->twig()->render('@NowoTaskBoardBundle/manage/gantt.html.twig', [
            'board'          => $this->board,
            'timeline'       => (new TaskGanttBuilder())->build([$this->task]),
            'dashboardRoute' => null,
        ]);

        self::assertStringContainsString('/host/board/' . $this->board->getId() . '/list', $html);
        self::assertStringContainsString('/host/task/' . $this->task->getId(), $html);
    }

    public function testTaskWorkflowPartialsUseConfiguredRouteNames(): void
    {
        $repository = $this->createMock(TaskRepositoryInterface::class);
        $context    = [
            'task'             => $this->task,
            'columnNavigation' => (new TaskManager($repository, new TaskChangeRecorder()))->getColumnNavigation($this->task),
        ];

        foreach (['_task_status_field', '_task_column_navigation'] as $partial) {
            $html = $this->twig()->render('@NowoTaskBoardBundle/manage/' . $partial . '.html.twig', $context);
            self::assertStringContainsString('/host/task/' . $this->task->getId() . '/advance', $html, $partial);
        }
    }

    public function testNoTemplateHardCodesABundleRouteName(): void
    {
        $pattern   = '/[\'"]nowo_task_board_(' . implode('|', self::ROUTE_KEYS) . ')[\'"]/';
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::VIEWS));
        $offenders = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders);
    }

    private function twig(): Environment
    {
        $renamed = [];
        foreach (self::ROUTE_KEYS as $key) {
            $renamed[$key] = ['name' => 'host_tb_' . $key];
        }
        $renamed['index']['path']        = '/host';
        $renamed['board']['path']        = '/host/board/{boardId}';
        $renamed['list']['path']         = '/host/board/{boardId}/list';
        $renamed['gantt']['path']        = '/host/board/{boardId}/gantt';
        $renamed['task']['path']         = '/host/task/{taskId}';
        $renamed['task_advance']['path'] = '/host/task/{taskId}/advance';

        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => TestUser::class,
            'routes'     => $renamed,
        ]]);
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes = $config['routes'];

        $collection = (new TaskBoardRouteLoader($routes, ''))->load('.', 'nowo_task_board');
        self::assertNull($collection->get('nowo_task_board_board'), 'default names must not be registered');

        $loader = new ChainLoader([
            new ArrayLoader([
                'layout.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block stylesheets %}{% endblock %}{% block javascripts %}{% endblock %}<main class="{% block container_class %}{% endblock %}">{% block body %}{% endblock %}</main>',
            ]),
            (static function (): FilesystemLoader {
                $filesystem = new FilesystemLoader([self::VENDOR . '/symfony/twig-bridge/Resources/views/Form']);
                $filesystem->addPath(self::VIEWS, 'NowoTaskBoardBundle');
                // Since 1.12 the icons go through UiKit's components/_icon.
                $filesystem->addPath(self::VENDOR . '/nowo-tech/ui-kit-bundle/src/Resources/views', 'NowoUiKitBundle');

                return $filesystem;
            })(),
        ]);

        $twig   = new Environment($loader, ['strict_variables' => true]);
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TaskBoardTwigExtension('layout.html.twig', 'tabler', $routes));
        $twig->addExtension(new RoutingExtension(new UrlGenerator($collection, new RequestContext())));
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/' . $path));

        return $twig;
    }
}
