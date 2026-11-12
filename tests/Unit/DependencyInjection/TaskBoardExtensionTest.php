<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use LogicException;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\ImportedTimeAggregatorListener;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimePeriodLock;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Dto\TaskFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Event\BoardAccessCheckEvent;
use Nowo\TaskBoardBundle\EventListener\TimeSpentAggregatorListener;
use Nowo\TaskBoardBundle\Exception\ColumnNotFoundException;
use Nowo\TaskBoardBundle\Gantt\ConfiguredWeekStartResolver;
use Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface;
use Nowo\TaskBoardBundle\Import\Dto\TaskImportResult;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTeamMemberRepository;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TenantPathUrlGenerator;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TaskBoardBundle\Service\NoTaskTimePeriodLock;
use Nowo\TaskBoardBundle\Service\NullTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Service\TaskManager;
use Nowo\TaskBoardBundle\Service\TaskTimeBillableDefaultProviderInterface;
use Nowo\TaskBoardBundle\Service\TaskTimePeriodLockInterface;
use Nowo\TaskBoardBundle\Tenancy\SlugTaskBoardTenantResolver;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
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

use function array_column;

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
        self::assertNull($container->getParameter('nowo_task_board.web_ui.layout_template'));
        self::assertTrue($container->hasDefinition(TaskBoardTwigExtension::class));
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

    /** Since 1.25: the imported-time totals listener exists only with TimeTrack >= 1.11 (its events). */
    public function testImportedTimeAggregatorNeedsTheTimeTrackImportEvents(): void
    {
        $with = new ContainerBuilder();
        $with->setParameter('kernel.environment', 'test');
        $with->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $with);
        self::assertTrue($with->hasDefinition(ImportedTimeAggregatorListener::class));
        self::assertFalse($with->getDefinition(ImportedTimeAggregatorListener::class)->hasTag('container.excluded'));
        $tags = $with->getDefinition(ImportedTimeAggregatorListener::class)->getTag('kernel.event_listener');
        self::assertSame(['onImported', 'onRemoved'], array_column($tags, 'method'));
        self::assertSame(['nowo_time_track.time_entry.imported', 'nowo_time_track.time_entries.removed'], array_column($tags, 'event'));

        // TimeTrack 1.10: the bridge loads, the listener does not.
        $without = new ContainerBuilder();
        $without->setParameter('kernel.environment', 'test');
        $without->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension(timeTrackImportedEvent: 'Nowo\\TimeTrackBundle\\Event\\MissingImportedEvent'))->load([['user_class' => User::class]], $without);
        self::assertTrue($without->hasDefinition(TaskBoardTaskProvider::class));
        // The resource exclusion leaves only Symfony's "excluded" marker, never a listener.
        self::assertTrue(!$without->hasDefinition(ImportedTimeAggregatorListener::class) || $without->getDefinition(ImportedTimeAggregatorListener::class)->hasTag('container.excluded'));
        self::assertFalse($without->hasDefinition(ImportedTimeAggregatorListener::class) && $without->getDefinition(ImportedTimeAggregatorListener::class)->hasTag('kernel.event_listener'));
    }

    /** Since 1.26.1: manual / calendar entries are aggregated only with TimeTrack >= 1.14 (TIME_ENTRY_CREATED). */
    public function testTimeSpentAggregatorListensToCreatedEntriesOnlyWhenTheEventExists(): void
    {
        if (!class_exists(TimeEntryCreatedEvent::class)) {
            self::markTestSkipped('nowo-tech/time-track-bundle >= 1.14 is not installed.');
        }
        $with = new ContainerBuilder();
        $with->setParameter('kernel.environment', 'test');
        $with->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $with);
        $tags = $with->getDefinition(TimeSpentAggregatorListener::class)->getTag('kernel.event_listener');
        self::assertSame([['event' => 'nowo_time_track.time_entry.created', 'method' => 'onTimeEntryCreated']], $tags);

        // TimeTrack < 1.14: no tag; the TIMER_STOP attribute alone still applies.
        $without = new ContainerBuilder();
        $without->setParameter('kernel.environment', 'test');
        $without->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension(timeTrackEntryCreatedEvent: 'Nowo\\TimeTrackBundle\\Event\\MissingCreatedEvent'))->load([['user_class' => User::class]], $without);
        self::assertTrue($without->hasDefinition(TimeSpentAggregatorListener::class));
        self::assertSame([], $without->getDefinition(TimeSpentAggregatorListener::class)->getTag('kernel.event_listener'));
    }

    /** Since 2.12.0: manual entries take TimeTrack's billable default (>= 1.21) and period lock (>= 1.13) when present. */
    public function testBillablePortsAreBridgedToTimeTrackWhenItHasThem(): void
    {
        $with = new ContainerBuilder();
        $with->setParameter('kernel.environment', 'test');
        $with->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $with);
        self::assertSame(TimeTrackTaskTimeBillableDefaultProvider::class, (string) $with->getAlias(TaskTimeBillableDefaultProviderInterface::class));
        self::assertSame(TimeTrackTaskTimePeriodLock::class, (string) $with->getAlias(TaskTimePeriodLockInterface::class));
        $defaults = $with->getDefinition(TimeTrackTaskTimeBillableDefaultProvider::class)->getArgument('$defaults');
        self::assertInstanceOf(Reference::class, $defaults);
        self::assertSame(TaskBoardExtension::TIME_TRACK_BILLABLE_DEFAULTS, (string) $defaults);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $defaults->getInvalidBehavior(), 'TimeTrack installed but not enabled');

        // An older TimeTrack: the bundle's own defaults (no default, no lock).
        $without = new ContainerBuilder();
        $without->setParameter('kernel.environment', 'test');
        $without->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension(timeTrackBillableDefaults: 'Nowo\\TimeTrackBundle\\Integration\\MissingPort', timeTrackPeriodLockGuard: 'Nowo\\TimeTrackBundle\\Timesheet\\MissingGuard'))->load([['user_class' => User::class]], $without);
        self::assertSame(NullTaskTimeBillableDefaultProvider::class, (string) $without->getAlias(TaskTimeBillableDefaultProviderInterface::class));
        self::assertSame(NoTaskTimePeriodLock::class, (string) $without->getAlias(TaskTimePeriodLockInterface::class));
        self::assertFalse($without->hasDefinition(TimeTrackTaskTimePeriodLock::class) && !$without->getDefinition(TimeTrackTaskTimePeriodLock::class)->hasTag('container.excluded'));
    }

    public function testWeekStartResolverFollowsTheGanttConfig(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        self::assertSame('monday', $container->getParameter('nowo_task_board.gantt.week_start'));
        self::assertSame(ConfiguredWeekStartResolver::class, (string) $container->getAlias(WeekStartResolverInterface::class));
        self::assertSame('monday', $container->getDefinition(ConfiguredWeekStartResolver::class)->getArgument('$weekStart'));

        $host = new ContainerBuilder();
        $host->setParameter('kernel.environment', 'test');
        $host->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'gantt' => ['week_start' => 'sunday', 'week_start_resolver' => 'app.week_start']]], $host);

        self::assertSame('sunday', $host->getParameter('nowo_task_board.gantt.week_start'));
        self::assertSame('app.week_start', (string) $host->getAlias(WeekStartResolverInterface::class));
    }

    public function testTheCauseLabelersGoToTheTwigExtension(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'history' => ['cause_labeler' => 'app.history_labeler'], 'comments' => ['cause_labeler' => 'app.comment_labeler']]], $container);

        $twig = $container->getDefinition(TaskBoardTwigExtension::class);
        self::assertSame('app.history_labeler', (string) $twig->getArgument('$causeLabeler'));
        self::assertSame('app.comment_labeler', (string) $twig->getArgument('$commentCauseLabeler'));
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

        self::assertSame('base.html.twig', $container->getParameter('nowo_task_board.web_ui.layout_template'));
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

    public function testPrependSeedsFormKitDefaultsAndLeavesUiKitAlone(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_form_kit'));
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_task_board', ['user_class' => User::class, 'templates' => ['css_framework' => 'bootstrap']]);

        (new TaskBoardExtension())->prepend($container);

        $formKit = $container->getExtensionConfig('nowo_form_kit');
        self::assertSame('bootstrap', $formKit[0]['css_framework']);
        self::assertSame('NowoTaskBoardBundle', $formKit[0]['profiles']['task_board']['translation_domain']);

        // Since 2.0.0 templates.css_framework is ignored: UiKit's own configuration decides.
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testWebUiWinsOverTheDeprecatedTemplatesKeys(): void
    {
        $container = new ContainerBuilder();
        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'security'   => ['allow_unauthenticated' => true],
            'templates'  => ['layout' => 'old.html.twig', 'index' => 'old_index.html.twig', 'board' => 'old_board.html.twig'],
            'web_ui'     => ['layout_template' => 'new.html.twig', 'templates' => ['index' => 'new_index.html.twig']],
        ]], $container);

        self::assertSame('new.html.twig', $container->getParameter('nowo_task_board.web_ui.layout_template'));
        $templates = $container->getParameter('nowo_task_board.templates');
        self::assertIsArray($templates);
        self::assertSame('new_index.html.twig', $templates['index']);
        self::assertSame('old_board.html.twig', $templates['board']);
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

    public function testTenancyDecoratesTheRouterAndAliasesTheResolvers(): void
    {
        $container = $this->securedContainer();

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'tenancy'    => [
                'enabled'      => true,
                'resolver'     => 'app.tenant_resolver',
                'tenant_paths' => [['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_']],
            ],
            'assignee_provider' => 'app.assignees',
        ]], $container);

        self::assertTrue($container->getParameter('nowo_task_board.tenancy.enabled'));
        self::assertSame('app_project_', $container->getParameter('nowo_task_board.tenancy.tenant_paths')[0]['name_prefix']);
        $decorator = $container->getDefinition(TenantPathUrlGenerator::class);
        self::assertSame(['router', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE], $decorator->getDecoratedService());
        self::assertSame(TenantPathUrlGenerator::class . '.inner', (string) $decorator->getArgument('$inner'));
        self::assertSame('app.tenant_resolver', (string) $container->getAlias(TaskBoardTenantResolverInterface::class));
        self::assertSame('app.assignees', (string) $container->getAlias(TaskAssigneeProviderInterface::class));
        self::assertArrayHasKey(TaskObjectLinkResolverInterface::class, $container->getAutoconfiguredInstanceof());
        self::assertTrue($container->getAutoconfiguredInstanceof()[TaskObjectLinkResolverInterface::class]->hasTag('nowo_task_board.object_link_resolver'));
    }

    public function testATenantPathOpenSingleBoardReachesTheControllerByNamePrefix(): void
    {
        $container = $this->securedContainer();

        (new TaskBoardExtension())->load([[
            'user_class' => User::class,
            'tenancy'    => [
                'enabled'           => true,
                'open_single_board' => true,
                'tenant_paths'      => [
                    ['path' => '/tasks/{tenantSlug}', 'name_prefix' => 'general_', 'slug_prefix' => 'user-', 'open_single_board' => false],
                    ['path' => '/organizations/{tenantSlug}/tasks', 'name_prefix' => 'org_', 'slug_prefix' => 'org-'],
                    ['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_', 'open_single_board' => true],
                ],
            ],
        ]], $container);

        $controller = $container->getDefinition(TaskBoardManageController::class);
        self::assertTrue($controller->getArgument('$openSingleBoard'));
        self::assertSame(['general_' => false, 'app_project_' => true], $controller->getArgument('$openSingleBoardPaths'));
        self::assertArrayNotHasKey('open_single_board', $container->getParameter('nowo_task_board.tenancy.tenant_paths')[1], 'not set: no key');
    }

    public function testWithoutTenancyTheRouterIsNotDecorated(): void
    {
        $container = $this->securedContainer();

        (new TaskBoardExtension())->load([['user_class' => User::class]], $container);

        // Excluded from the resource scan (kept as a "container.excluded" placeholder): no decorator.
        self::assertTrue($container->getDefinition(TenantPathUrlGenerator::class)->hasTag('container.excluded'));
        self::assertFalse($container->hasAlias(TaskAssigneeProviderInterface::class));
        self::assertSame('nowo_task_board.tenant_resolver.slug', (string) $container->getAlias(TaskBoardTenantResolverInterface::class));
        self::assertSame(SlugTaskBoardTenantResolver::class, $container->getDefinition('nowo_task_board.tenant_resolver.slug')->getClass());
        self::assertFalse($container->getParameter('nowo_task_board.board.top_level_only'));
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
