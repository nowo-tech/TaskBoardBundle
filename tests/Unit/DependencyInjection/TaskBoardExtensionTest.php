<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use LogicException;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTeamMemberRepository;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class TaskBoardExtensionTest extends TestCase
{
    public function testLoadSetsParametersAndAliases(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
        ]], $container);

        self::assertSame(User::class, $container->getParameter('nowo_task_board.user_class'));
        self::assertSame('task_board_tasks', $container->getParameter('nowo_task_board.tasks_table'));
        self::assertSame('@NowoTaskBoardBundle/layout.html.twig', $container->getParameter('nowo_task_board.templates.layout'));
        self::assertSame('tabler', $container->getParameter('nowo_task_board.templates.css_framework'));
        self::assertTrue($container->hasDefinition(TaskBoardTwigExtension::class));
        $twigDefinition = $container->getDefinition(TaskBoardTwigExtension::class);
        self::assertSame('%nowo_task_board.templates.layout%', $twigDefinition->getArgument('$layoutTemplate'));
        self::assertSame('%nowo_task_board.templates.css_framework%', $twigDefinition->getArgument('$cssFramework'));
        self::assertTrue($container->hasAlias(TaskRepositoryInterface::class));

        $managerRegistry = $container->getDefinition(DoctrineOrmTaskRepository::class)->getArgument('$managerRegistry');
        self::assertInstanceOf(Reference::class, $managerRegistry);
        self::assertSame('doctrine', (string) $managerRegistry);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $managerRegistry->getInvalidBehavior());
        self::assertArrayNotHasKey('$managerRegistry', $container->getDefinition(DoctrineOrmTeamMemberRepository::class)->getArguments());

        if (interface_exists(TaskProviderInterface::class)) {
            self::assertTrue($container->hasAlias('nowo_task_board.task_provider'));
            self::assertTrue($container->hasAlias('nowo_task_board.team_context_provider'));
            self::assertTrue($container->hasDefinition(TaskBoardTaskProvider::class));
        } else {
            self::assertFalse($container->hasAlias('nowo_task_board.task_provider'));
            self::assertFalse($container->hasAlias('nowo_task_board.team_context_provider'));
        }
    }

    public function testPrependRegistersFrameworkAssetsAndDoctrineMapping(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FrameworkExtension());
        $container->registerExtension(new DoctrineExtension());

        (new TaskBoardExtension())->prepend($container);

        self::assertNotEmpty($container->getExtensionConfig('framework'));
        self::assertNotEmpty($container->getExtensionConfig('doctrine'));
    }

    public function testPrependNoOpWithoutOptionalExtensions(): void
    {
        $container = new ContainerBuilder();

        (new TaskBoardExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_task_board', (new TaskBoardExtension())->getAlias());
    }

    public function testLoadWithCustomAccessCheckerAndTeamResolver(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        $container->setDefinition('app.access_checker', new Definition(stdClass::class));
        $container->setDefinition('app.team_resolver', new Definition(stdClass::class));

        (new TaskBoardExtension())->load([[
            'user_class'               => User::class,
            'team_membership_resolver' => 'app.team_resolver',
            'security'                 => ['access_checker' => 'app.access_checker'],
        ]], $container);

        self::assertSame('app.access_checker', (string) $container->getAlias(TaskBoardAccessCheckerInterface::class));
        self::assertSame('app.team_resolver', (string) $container->getAlias(TaskBoardTeamMembershipResolverInterface::class));
    }

    public function testLoadRegistersDefaultAccessCheckerAndNullTeamResolver(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        self::assertTrue($container->hasDefinition('nowo_task_board.access_checker.default'));
        self::assertTrue($container->hasDefinition('nowo_task_board.team_resolver.null'));

        $resource = $container->getDefinition(TaskBoardResourceAccessChecker::class);
        self::assertSame(['ROLE_ADMIN'], $resource->getArgument('$manageRoles'));
        self::assertTrue($resource->getArgument('$scopingEnabled'));
        self::assertSame('security.authorization_checker', (string) $resource->getArgument('$authorizationChecker'));
        self::assertSame('event_dispatcher', (string) $resource->getArgument('$eventDispatcher'));
    }

    public function testLoadHonoursResourceScopingConfig(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'security'   => ['resource_scoping' => false, 'manage_roles' => ['ROLE_TASK_ADMIN']],
        ]], $container);

        $resource = $container->getDefinition(TaskBoardResourceAccessChecker::class);
        self::assertSame(['ROLE_TASK_ADMIN'], $resource->getArgument('$manageRoles'));
        self::assertFalse($resource->getArgument('$scopingEnabled'));
    }

    public function testLoadHonoursCustomTemplatesLayout(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'templates'  => ['layout' => 'base.html.twig'],
        ]], $container);

        self::assertSame('base.html.twig', $container->getParameter('nowo_task_board.templates.layout'));
        self::assertSame('base.html.twig', $container->getParameter('nowo_task_board.templates')['layout']);
    }

    public function testLoadHonoursCustomCssFramework(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'templates'  => ['css_framework' => 'bootstrap5'],
        ]], $container);

        self::assertSame('bootstrap5', $container->getParameter('nowo_task_board.templates.css_framework'));
        self::assertSame('bootstrap5', $container->getParameter('nowo_task_board.templates')['css_framework']);
    }

    public function testLoadThrowsWhenSecurityBundleMissingAndUnauthenticatedDisallowed(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('allow_unauthenticated');

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'security'   => ['allow_unauthenticated' => false],
        ]], $container);
    }

    public function testLoadRegistersAllowAllAccessCheckerWhenUnauthenticatedAllowed(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'security'   => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertTrue($container->hasDefinition('nowo_task_board.access_checker.allow_all'));
        self::assertSame(
            'nowo_task_board.access_checker.allow_all',
            (string) $container->getAlias(TaskBoardAccessCheckerInterface::class),
        );
        self::assertTrue($container->getParameter('nowo_task_board.security.allow_unauthenticated'));
        self::assertFalse($container->getDefinition(TaskBoardResourceAccessChecker::class)->getArgument('$scopingEnabled'));
    }

    public function testLoadSkipsTimeTrackBridgeWhenInterfaceIsMissing(): void
    {
        $container = $this->securedContainer();

        (new TaskBoardExtension('Nowo\\Missing\\TaskProviderInterface'))->load([['user_class' => User::class]], $container);

        self::assertFalse($container->hasAlias('nowo_task_board.task_provider'));
        self::assertFalse($container->hasDefinition(TaskBoardTaskProvider::class));
    }

    public function testLoadAcceptsSecurityExtensionWithoutKernelBundles(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('security'));

        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        self::assertTrue($container->hasDefinition('nowo_task_board.access_checker.default'));
    }

    public function testLoadPassesConfiguredRoutesToTwigExtension(): void
    {
        $container = $this->securedContainer();

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'routes'     => ['board' => ['name' => 'app_board']],
        ]], $container);

        self::assertSame('%nowo_task_board.routes%', $container->getDefinition(TaskBoardTwigExtension::class)->getArgument('$routes'));
        self::assertSame('app_board', $container->getParameter('nowo_task_board.routes')['board']['name']);
    }

    public function testLoadDoesNotRegisterDataClassesAsServices(): void
    {
        $container = $this->securedContainer();

        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        foreach ([Task::class, TaskBoard::class, TaskFormData::class, TaskImportResult::class, BoardAccessCheckEvent::class, TaskPriority::class, TaskImportSource::class, ColumnNotFoundException::class, Uuid::class] as $class) {
            // Excluded single files are kept as "container.excluded" placeholders (removed at compile time).
            self::assertTrue(
                !$container->hasDefinition($class) || $container->getDefinition($class)->hasTag('container.excluded'),
                $class . ' must not be a service',
            );
        }

        self::assertTrue($container->hasDefinition(TaskManager::class));
    }

    public function testPrependSeedsFormKitAndUiKitDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_form_kit'));
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_task_board', ['user_class' => User::class, 'templates' => ['css_framework' => 'bootstrap']]);

        (new TaskBoardExtension())->prepend($container);

        $formKit = $container->getExtensionConfig('nowo_form_kit');
        self::assertSame('bootstrap', $formKit[0]['css_framework']);
        self::assertSame('NowoTaskBoardBundle', $formKit[0]['profiles']['task_board']['translation_domain']);

        self::assertSame([['css_framework' => 'bootstrap5', 'icon_set' => 'bootstrap-icons']], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPrependUiKitUsesTablerIconsForTabler(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tabler']);
        $container->prependExtensionConfig('nowo_task_board', ['user_class' => User::class]);

        (new TaskBoardExtension())->prepend($container);

        self::assertSame(['icon_set' => 'tabler-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testPrependKeepsHostFormKitAndUiKitSettings(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_form_kit'));
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_form_kit', ['css_framework' => 'tailwind', 'profiles' => ['task_board' => ['alias' => 'mine']]]);
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tailwind', 'icon_set' => 'none']);

        (new TaskBoardExtension())->prepend($container);

        self::assertCount(1, $container->getExtensionConfig('nowo_form_kit'));
        self::assertCount(1, $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPrependSeedsTiptapTaskProfileWithHostDefaultFormTheme(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_tiptap_editor'));
        $container->prependExtensionConfig('nowo_tiptap_editor', [
            'default_profile' => 'basic',
            'profiles'        => ['basic' => ['form_theme' => 'bootstrap_5_layout.html.twig']],
        ]);

        (new TaskBoardExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_tiptap_editor');
        self::assertCount(2, $configs);
        self::assertSame([
            'profiles' => [
                TaskBoardExtension::TIPTAP_TASK_PROFILE => [
                    'variant'    => 'simple',
                    'toolbar'    => true,
                    'min_height' => '220px',
                    'form_theme' => 'bootstrap_5_layout.html.twig',
                ],
            ],
        ], $configs[0]);
    }

    public function testPrependTiptapTaskProfileReadsLegacyFlatFormTheme(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_tiptap_editor'));
        $container->prependExtensionConfig('nowo_tiptap_editor', ['form_theme' => 'tailwind_2_layout.html.twig', 'toolbar' => false]);

        (new TaskBoardExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_tiptap_editor');
        self::assertSame('tailwind_2_layout.html.twig', $configs[0]['profiles']['task']['form_theme']);
        self::assertArrayNotHasKey('default', $configs[0]['profiles']);
    }

    public function testPrependTiptapTaskProfileWithoutHostConfigKeepsDefaultProfileValid(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_tiptap_editor'));

        (new TaskBoardExtension())->prepend($container);

        self::assertSame([[
            'profiles' => [
                'task'    => ['variant' => 'simple', 'toolbar' => true, 'min_height' => '220px'],
                'default' => [],
            ],
        ]], $container->getExtensionConfig('nowo_tiptap_editor'));
    }

    public function testPrependTiptapTaskProfileOmitsFormThemeWhenDefaultProfileIsElsewhere(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_tiptap_editor'));
        $container->prependExtensionConfig('nowo_tiptap_editor', ['profiles' => ['blog' => ['form_theme' => 'bootstrap_5_layout.html.twig']]]);

        (new TaskBoardExtension())->prepend($container);

        self::assertSame(
            ['variant' => 'simple', 'toolbar' => true, 'min_height' => '220px'],
            $container->getExtensionConfig('nowo_tiptap_editor')[0]['profiles']['task'],
        );
    }

    public function testPrependKeepsHostTiptapTaskProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_tiptap_editor'));
        $container->prependExtensionConfig('nowo_tiptap_editor', ['default_config' => 'task', 'configs' => ['task' => ['variant' => 'notion']]]);

        (new TaskBoardExtension())->prepend($container);

        self::assertCount(1, $container->getExtensionConfig('nowo_tiptap_editor'));
    }

    public function testPrependSkipsTiptapWhenBundleMissing(): void
    {
        $container = new ContainerBuilder();

        (new TaskBoardExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_tiptap_editor'));
    }

    private function securedContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        return $container;
    }

    private function stubExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
