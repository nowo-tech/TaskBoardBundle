<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection;

use LogicException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Api\Doctrine\JsonTextFunction;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiAttachments;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiPlanGate;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiRateLimiter;
use Nowo\TaskBoardBundle\Api\Hook\DenyingTaskApiTimeEntries;
use Nowo\TaskBoardBundle\Api\Hook\EmptyTaskApiUsers;
use Nowo\TaskBoardBundle\Api\Hook\EmptyTaskApiWorkspace;
use Nowo\TaskBoardBundle\Api\Hook\ManualTaskApiTimeEntries;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAttachmentsInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiIdempotencyStoreInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiPlanGateInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiRateLimiterInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiTimeEntriesInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiUsersInterface;
use Nowo\TaskBoardBundle\Api\Hook\TaskApiWorkspaceInterface;
use Nowo\TaskBoardBundle\Api\Idempotency\DoctrineTaskApiIdempotencyStore;
use Nowo\TaskBoardBundle\Api\Repository\TaskApiQueryRepository;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\ImportedTimeAggregatorListener;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTeamContextProvider;
use Nowo\TaskBoardBundle\Comment\DefaultTaskMentionCandidatesResolver;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Controller\TaskBoardManageController;
use Nowo\TaskBoardBundle\Controller\TaskCopyController;
use Nowo\TaskBoardBundle\Copy\TaskCopier;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\EventListener\TimeSpentAggregatorListener;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Gantt\ConfiguredWeekStartResolver;
use Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface;
use Nowo\TaskBoardBundle\Import\ImportHelpLinkResolver;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use Nowo\TaskBoardBundle\Move\DefaultTaskBoardTargets;
use Nowo\TaskBoardBundle\Move\TaskBoardTargetsInterface;
use Nowo\TaskBoardBundle\Move\TaskMovePlanner;
use Nowo\TaskBoardBundle\Move\TaskMover;
use Nowo\TaskBoardBundle\Number\TaskNumberBackfiller;
use Nowo\TaskBoardBundle\Number\TaskNumberLedger;
use Nowo\TaskBoardBundle\Number\TaskNumberListener;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceBoardListener;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceManager;
use Nowo\TaskBoardBundle\Recurrence\TaskRecurrenceView;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\ColumnSetRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmBoardColumnRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmColumnSetRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskBoardRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskCommentRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDependencyRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDocumentRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskLinkRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskMemberRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskTimeEntryRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTeamMemberRepository;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskCommentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskDependencyRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskDocumentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskLinkRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskTimeEntryRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Nowo\TaskBoardBundle\Routing\TenantPaths;
use Nowo\TaskBoardBundle\Routing\TenantPathUrlGenerator;
use Nowo\TaskBoardBundle\Security\AllowAllTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use Nowo\TaskBoardBundle\Sync\Field\SyncCustomFieldWriter;
use Nowo\TaskBoardBundle\Sync\File\OutboundUrlGuardAdapter;
use Nowo\TaskBoardBundle\Sync\File\UrlGuardInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionCreatorActorResolver;
use Nowo\TaskBoardBundle\Sync\Hook\DefaultSyncPersonResolver;
use Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\InlineSyncRunner;
use Nowo\TaskBoardBundle\Sync\Hook\MapInlineImageRewriter;
use Nowo\TaskBoardBundle\Sync\Hook\NullSyncLimitChecker;
use Nowo\TaskBoardBundle\Sync\Hook\NullTaskAttachmentStorage;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncActorResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncLimitCheckerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncRunnerInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TaskAttachmentStorageInterface;
use Nowo\TaskBoardBundle\Sync\People\PeopleMappingService;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface as SyncTaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\SyncChecklistWriter;
use Nowo\TaskBoardBundle\Sync\SyncTaskDetailsWriter;
use Nowo\TaskBoardBundle\Sync\SyncTaskWriter;
use Nowo\TaskBoardBundle\Sync\Time\TimeEntryPhase;
use Nowo\TaskBoardBundle\Sync\View\ConnectionBadgeProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncFieldViewProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tenancy\SlugTaskBoardTenantResolver;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\View\NullTaskBoardDefaultViewResolver;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;
use Nowo\TimeTrackBundle\Event\TimeEntryCreatedEvent;
use Nowo\TimeTrackBundle\Event\TimeEntryImportedEvent;
use Nowo\TimeTrackBundle\Event\TimeTrackEvents;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function class_exists;
use function defined;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Loads bundle configuration and registers services.
 */
final class TaskBoardExtension extends Extension implements PrependExtensionInterface
{
    private const TIPTAP_EXTENSION = 'nowo_tiptap_editor';

    /** Tiptap profile used by {@see TaskFormType} for the description. */
    public const TIPTAP_TASK_PROFILE = 'task';

    /**
     * @param string $timeTrackProviderInterface Interface whose presence enables the optional TimeTrack bridge
     * @param string $timeTrackImportedEvent Since 1.25: event class whose presence (TimeTrack >= 1.11) enables the
     *                                       imported-time aggregation
     * @param string $timeTrackEntryCreatedEvent Since 1.26.1: event class whose presence (TimeTrack >= 1.14) enables the
     *                                           aggregation of manual and calendar entries
     */
    public function __construct(
        private readonly string $timeTrackProviderInterface = TaskProviderInterface::class,
        private readonly string $timeTrackImportedEvent = TimeEntryImportedEvent::class,
        private readonly string $timeTrackEntryCreatedEvent = TimeEntryCreatedEvent::class,
    ) {
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $prefix = rtrim((string) $config['table_prefix'], '_');
        $emName = (string) $config['database']['entity_manager'];

        $container->setParameter('nowo_task_board.user_class', $config['user_class']);
        $container->setParameter('nowo_task_board.table_prefix', $config['table_prefix']);
        $container->setParameter('nowo_task_board.tasks_table', $prefix . '_tasks');
        $container->setParameter('nowo_task_board.boards_table', $prefix . '_boards');
        $container->setParameter('nowo_task_board.teams_table', $prefix . '_teams');
        $container->setParameter('nowo_task_board.team_members_table', $prefix . '_team_members');
        $container->setParameter('nowo_task_board.route_prefix', $config['route_prefix']);
        $container->setParameter('nowo_task_board.dashboard_route', $config['dashboard_route']);
        $container->setParameter('nowo_task_board.routes', $config['routes']);
        // Since 2.0.0: web_ui.* is the canonical name; templates.* still works, deprecated.
        $config['templates'] = $config['web_ui']['templates'] + $config['templates'];
        $container->setParameter('nowo_task_board.templates', $config['templates']);
        $container->setParameter('nowo_task_board.web_ui.layout_template', $config['web_ui']['layout_template'] ?? $config['templates']['layout']);
        $container->setParameter('nowo_task_board.templates.page_header', $config['templates']['page_header']);
        $container->setParameter('nowo_task_board.board.toolbar', $config['board']['toolbar']);
        $container->setParameter('nowo_task_board.task_panel.toolbar', $config['task_panel']['toolbar']);
        $container->setParameter('nowo_task_board.templates.task_time_before', $config['templates']['task_time_before']);
        $container->setParameter('nowo_task_board.templates.filters_collapse', $config['templates']['filters_collapse']);
        $container->setParameter('nowo_task_board.templates.filters_form_class', $config['templates']['filters_form_class']);
        $container->setParameter('nowo_task_board.templates.connection_badge', $config['templates']['connection_badge']);
        // Since 1.25.2: read by the Twig globals even without sync.enabled (the badge is then never rendered).
        $container->setParameter('nowo_task_board.sync.badge.connect_link', (bool) $config['sync']['badge']['connect_link']);
        // Since 2.5.0 (TB-16).
        $container->setParameter('nowo_task_board.sync.badge.style', $config['sync']['badge']['style']);
        $container->setParameter('nowo_task_board.firewall', $config['firewall']);
        $container->setParameter('nowo_task_board.security', $config['security']);
        $container->setParameter('nowo_task_board.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_task_board.tenancy', $config['tenancy']);
        $container->setParameter('nowo_task_board.tenancy.enabled', $config['tenancy']['enabled']);
        $container->setParameter('nowo_task_board.tenancy.single_board', $config['tenancy']['single_board']);
        $container->setParameter('nowo_task_board.tenancy.tenant_paths', $config['tenancy']['tenant_paths']);
        $container->setParameter('nowo_task_board.board.default_columns', $config['board']['default_columns']);
        $container->setParameter('nowo_task_board.board.top_level_only', $config['board']['top_level_only']);
        $container->setParameter('nowo_task_board.board.column_icons', $config['board']['column_icons']);
        $container->setParameter('nowo_task_board.board.max_tasks', $config['board']['max_tasks']);
        $container->setParameter('nowo_task_board.board.create_dialog_header', $config['board']['create_dialog_header']);
        // Since 1.30.0 (spec 211).
        $container->setParameter('nowo_task_board.board.form_options', [
            'icon_field'               => $config['board']['icon'],
            'icon_form_type'           => $config['board']['icon_form_type'],
            'icon_form_options'        => $config['board']['icon_form_options'],
            'description_form_type'    => $config['board']['description_form_type'],
            'description_form_options' => $config['board']['description_form_options'],
            'advanced_slug'            => $config['board']['advanced_slug'],
        ]);
        $container->setParameter('nowo_task_board.board.advanced_slug', $config['board']['advanced_slug']);
        $container->setParameter('nowo_task_board.board.description_format', $config['board']['description_format'] === 'markdown' ? 'markdown' : null);
        $container->setParameter('nowo_task_board.import.max_rows', $config['import']['max_rows']);
        $container->setParameter('nowo_task_board.import.max_cell_length', $config['import']['max_cell_length']);
        $container->setParameter('nowo_task_board.import.max_columns', $config['import']['max_columns']);
        $container->setParameter('nowo_task_board.import.max_cells', $config['import']['max_cells']);
        $container->setParameter('nowo_task_board.import.max_json_nodes', $config['import']['max_json_nodes']);
        $container->setParameter('nowo_task_board.gantt.week_start', $config['gantt']['week_start']);
        $container->setParameter('nowo_task_board.comments.readers_can_comment', $config['comments']['readers_can_comment']);

        if (
            !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('TaskBoardBundle manage UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $this->registerRepositories($container, $emName);
        $this->registerMetadataListener($container, $prefix, $config['user_class']);
        $this->registerAccessChecker($container, $config['security']);
        $this->registerResourceAccessChecker($container, $config['security']);
        $this->registerTeamResolver($container, $config['team_membership_resolver'] ?? null);
        $this->registerTenantResolver($container, $config['tenancy']['resolver']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
        $this->registerTimeTrackIntegration($container, $loader);
        $this->registerTenantUrlGenerator($container, $config['tenancy']['enabled']);
        $this->registerAssigneeProvider($container, $config['assignee_provider']);
        $this->registerWeekStartResolver($container, $config['gantt']);
        $this->registerMentionResolver($container, $config['comments']['mention_resolver']);
        // Since 1.24: the host's default board view per user, else the board view.
        $container->setAlias(TaskBoardDefaultViewResolverInterface::class, $this->serviceOr($config['board']['default_view_resolver'], NullTaskBoardDefaultViewResolver::class));
        $container->registerForAutoconfiguration(TaskObjectLinkResolverInterface::class)
            ->addTag('nowo_task_board.object_link_resolver');
        $this->registerSync($container, $loader, $config['sync']);
        $this->registerTaskNumbersAndCalendar($container, $loader, $config, $emName);
        $this->registerMoveAndCopy($container, $config, $emName);
        $this->registerRecurrence($container, $loader, $config, $emName);
        // Since 2.3.0 (spec 216): the host's label of a history row's cause.
        if (is_string($config['history']['cause_labeler']) && $config['history']['cause_labeler'] !== '') {
            $container->getDefinition(TaskBoardTwigExtension::class)->setArgument('$causeLabeler', new Reference($config['history']['cause_labeler']));
        }
        $this->registerBoardCreation($container, $config['board']);
        $this->registerHostHooks($container, $config);
        $this->registerApi($container, $loader, $config, $emName);
    }

    /**
     * Since 2.2.0 (spec 213): `api.*`. With `enabled: false` nothing is registered: no route, service, listener or parameter.
     *
     * @param array<string, mixed> $config
     */
    private function registerApi(ContainerBuilder $container, YamlFileLoader $loader, array $config, string $emName): void
    {
        $api = $config['api'];
        if (!$api['enabled']) {
            return;
        }
        if (!is_string($api['hooks']['authenticator']) || $api['hooks']['authenticator'] === '') {
            throw new LogicException('nowo_task_board.api.enabled needs api.hooks.authenticator: the service id of a Nowo\\TaskBoardBundle\\Api\\Hook\\TaskApiAuthenticatorInterface (the host keeps the tokens).');
        }
        if ($config['security']['allow_unauthenticated']) {
            throw new LogicException('nowo_task_board.api.enabled cannot be used with security.allow_unauthenticated: every board would be open to every token.');
        }
        if (!$config['security']['resource_scoping'] && !$api['host_decides_access']) {
            throw new LogicException('nowo_task_board.api.enabled with security.resource_scoping: false opens every board to every token unless listeners deny it. Set api.host_decides_access: true if yours do.');
        }

        $container->setParameter('nowo_task_board.api.prefix', $api['prefix']);
        $container->setParameter('nowo_task_board.api.quota_listener_required', $api['quota_listener_required']);
        $container->setParameter('nowo_task_board.api.max_body_bytes', $api['max_body_bytes']);
        $loader->load('services_api.yaml');
        $container->setAlias(TaskApiAuthenticatorInterface::class, $api['hooks']['authenticator']);
        $container->setAlias(TaskApiRateLimiterInterface::class, $this->serviceOr($api['hooks']['rate_limiter'], DenyingTaskApiRateLimiter::class));
        $container->setAlias(TaskApiPlanGateInterface::class, $this->serviceOr($api['hooks']['plan_gate'], DenyingTaskApiPlanGate::class));
        $container->setAlias(TaskApiWorkspaceInterface::class, $this->serviceOr($api['hooks']['workspace'], EmptyTaskApiWorkspace::class));
        $container->setAlias(TaskApiUsersInterface::class, $this->serviceOr($api['hooks']['users'], EmptyTaskApiUsers::class));
        $container->setAlias(TaskApiTimeEntriesInterface::class, $this->serviceOr($api['hooks']['time_entries'], DenyingTaskApiTimeEntries::class));
        $container->setAlias(TaskApiAttachmentsInterface::class, $this->serviceOr($api['hooks']['attachments'], DenyingTaskApiAttachments::class));
        if (is_string($api['hooks']['idempotency_store']) && $api['hooks']['idempotency_store'] !== '') {
            $container->setAlias(TaskApiIdempotencyStoreInterface::class, $api['hooks']['idempotency_store']);
        } else {
            // Since 2.2.0: the bundle's own store, on the bundle's entity manager; its table is mapped in prepend().
            $container->setDefinition(DoctrineTaskApiIdempotencyStore::class, (new Definition(DoctrineTaskApiIdempotencyStore::class))
                ->setAutowired(true)
                ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName))));
            $container->setAlias(TaskApiIdempotencyStoreInterface::class, DoctrineTaskApiIdempotencyStore::class);
        }
        $container->getDefinition(TaskBoardRouteLoader::class)->setArgument('$apiPrefix', $api['prefix']);
        $container->getDefinition(TaskApiQueryRepository::class)->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName)));
        $container->getDefinition(ManualTaskApiTimeEntries::class)->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName)));
    }

    /**
     * Since 2.3.0 (spec 215): `recurrence.*`. With `enabled: false` nothing is registered: no route, service, mapping or
     * output change.
     *
     * @param array<string, mixed> $config
     */
    private function registerRecurrence(ContainerBuilder $container, YamlFileLoader $loader, array $config, string $emName): void
    {
        $enabled = (bool) $config['recurrence']['enabled'];
        $container->setParameter('nowo_task_board.recurrence.enabled', $enabled);
        if (!$enabled) {
            return;
        }
        $container->setParameter('nowo_task_board.recurrence.max_subtasks', $config['recurrence']['max_subtasks']);
        $loader->load('services_recurrence.yaml');
        $manager = new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));
        $container->getDefinition(TaskRecurrenceManager::class)->setArgument('$entityManager', $manager);
        $container->getDefinition(TaskRecurrenceBoardListener::class)->setArgument('$entityManager', $manager);
        $container->getDefinition(TaskBoardRouteLoader::class)->setArgument('$recurrenceEnabled', true);
        $container->getDefinition(TaskBoardTwigExtension::class)->setArgument('$recurrence', new Reference(TaskRecurrenceView::class));
    }

    /**
     * Since 2.3.0 (spec 217): `move.*`. The copier, the planner and the mover are services either way (a host or a worker may
     * call them); with `enabled: false` there is no route and no panel action, so nothing changes for a host.
     *
     * @param array<string, mixed> $config
     */
    private function registerMoveAndCopy(ContainerBuilder $container, array $config, string $emName): void
    {
        $move    = $config['move'];
        $enabled = (bool) $move['enabled'];
        $manager = new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));
        $sync    = (bool) $config['sync']['enabled'];
        $container->setParameter('nowo_task_board.move.enabled', $enabled);
        $container->setParameter('nowo_task_board.move.max_subtasks', $move['max_subtasks']);
        $container->getDefinition(TaskBoardRouteLoader::class)->setArgument('$moveEnabled', $enabled);
        $container->getDefinition(TaskCopyController::class)->setArgument('$templates', '%nowo_task_board.templates%');
        $container->getDefinition(TaskBoardTwigExtension::class)
            ->setArgument('$moveEnabled', $enabled)
            ->setArgument('$resourceAccess', new Reference(TaskBoardResourceAccessChecker::class));
        $container->getDefinition(TaskCopier::class)
            ->setArgument('$entityManager', $manager)
            ->setArgument('$maxSubtasks', $move['max_subtasks'])
            ->setArgument('$syncEnabled', $sync);
        $container->getDefinition(TaskMovePlanner::class)
            ->setArgument('$entityManager', $manager)
            ->setArgument('$maxSubtasks', $move['max_subtasks'])
            ->setArgument('$syncEnabled', $sync);
        $container->getDefinition(TaskMover::class)
            ->setArgument('$entityManager', $manager)
            ->setArgument('$syncEnabled', $sync);
        $container->setAlias(TaskBoardTargetsInterface::class, $this->serviceOr($move['targets'], DefaultTaskBoardTargets::class));
    }

    /**
     * Since 2.1.0 (spec 214): `task_numbers.*` and `calendar.enabled`. With both off nothing changes for a host.
     *
     * @param array<string, mixed> $config
     */
    private function registerTaskNumbersAndCalendar(ContainerBuilder $container, YamlFileLoader $loader, array $config, string $emName): void
    {
        $numbers  = (bool) $config['task_numbers']['enabled'];
        $calendar = (bool) $config['calendar']['enabled'];
        $container->setParameter('nowo_task_board.task_numbers.enabled', $numbers);
        $container->setParameter('nowo_task_board.calendar.enabled', $calendar);
        $container->getDefinition(TaskBoardRouteLoader::class)
            ->setArgument('$taskNumbersEnabled', $numbers)
            ->setArgument('$calendarEnabled', $calendar);
        $container->getDefinition(TaskBoardManageController::class)->setArgument('$calendarEnabled', $calendar);
        $container->getDefinition(TaskBoardTwigExtension::class)
            ->setArgument('$taskNumbersEnabled', $numbers)
            ->setArgument('$calendarEnabled', $calendar);
        if (!$numbers) {
            return;
        }

        $container->setParameter('nowo_task_board.task_numbers.scope', $config['task_numbers']['scope']);
        $loader->load('services_numbers.yaml');
        $manager = new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));
        $container->getDefinition(TaskNumberLedger::class)->setArgument('$entityManager', $manager);
        $container->getDefinition(TaskNumberBackfiller::class)->setArgument('$entityManager', $manager);
        foreach (['onFlush', 'postPersist', 'postUpdate'] as $event) {
            $container->getDefinition(TaskNumberListener::class)->addTag('doctrine.event_listener', ['event' => $event, 'entity_manager' => $emName]);
        }
    }

    /**
     * Since 2.0.0: `tenancy.open_single_board`, `tenancy.first_board_provisioner` and `import.help_link`.
     *
     * @param array<string, mixed> $config
     */
    private function registerHostHooks(ContainerBuilder $container, array $config): void
    {
        $provisioner = $config['tenancy']['first_board_provisioner'];
        $container->getDefinition(TaskBoardManageController::class)
            ->setArgument('$openSingleBoard', $config['tenancy']['open_single_board'])
            ->setArgument('$firstBoardProvisioner', is_string($provisioner) && $provisioner !== '' ? new Reference($provisioner) : null);
        $helpLink    = $config['import']['help_link'];
        $urlResolver = $helpLink['url_resolver'] ?? null;
        $container->getDefinition(ImportHelpLinkResolver::class)
            ->setArgument('$config', $helpLink)
            ->setArgument('$authorization', new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$urlResolver', is_string($urlResolver) && $urlResolver !== '' ? new Reference($urlResolver) : null);
    }

    /**
     * Since 1.30.0 (spec 211): the host's scope step and tenant labels, and what the New board form asks.
     *
     * @param array<string, mixed> $board
     */
    private function registerBoardCreation(ContainerBuilder $container, array $board): void
    {
        $reference = static fn (mixed $id): ?Reference => is_string($id) && $id !== '' ? new Reference($id) : null;
        $container->getDefinition(TaskBoardManageController::class)
            ->setArgument('$scopeProvider', $reference($board['scope_provider']))
            ->setArgument('$boardFormOptions', '%nowo_task_board.board.form_options%');
        $container->getDefinition(TaskBoardTwigExtension::class)
            ->setArgument('$tenantLabels', $reference($board['tenant_label_provider']))
            ->setArgument('$tokenStorage', new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $container->getDefinition(TaskBoardCreator::class)
            ->setArgument('$transliterateSlug', '%nowo_task_board.board.advanced_slug%')
            ->setArgument('$descriptionFormat', '%nowo_task_board.board.description_format%');
    }

    /**
     * Since 1.23: provider connections (`sync.*`), only when enabled — the routes, the services, the Doctrine mapping
     * of the sync tables and the header block. A missing `credential_resolver` stops the container build.
     *
     * @param array<string, mixed> $sync
     */
    private function registerSync(ContainerBuilder $container, YamlFileLoader $loader, array $sync): void
    {
        $container->setParameter('nowo_task_board.sync.enabled', (bool) $sync['enabled']);
        if (!$sync['enabled']) {
            return;
        }
        $resolver = $sync['credential_resolver'];
        if (!is_string($resolver) || $resolver === '') {
            throw new LogicException('nowo_task_board.sync.enabled needs sync.credential_resolver: the service id of a Nowo\\TaskBoardBundle\\Sync\\Hook\\ProviderCredentialResolverInterface (the host keeps the provider tokens).');
        }

        $container->setParameter('nowo_task_board.sync.http.timeout', (float) $sync['http']['timeout']);
        $container->setParameter('nowo_task_board.sync.http.max_duration', (float) $sync['http']['max_duration']);
        $container->setParameter('nowo_task_board.sync.http.max_response_bytes', $sync['http']['max_response_bytes']);
        $container->setParameter('nowo_task_board.sync.http.max_inline_wait', $sync['http']['max_inline_wait']);
        $container->setParameter('nowo_task_board.sync.attachments.max_bytes', $sync['attachments']['max_bytes']);
        $container->setParameter('nowo_task_board.sync.attachments.max_bytes_per_run', $sync['attachments']['max_bytes_per_run']);
        $container->setParameter('nowo_task_board.sync.attachments.max_redirects', $sync['attachments']['max_redirects']);
        $container->setParameter('nowo_task_board.sync.attachments.allowed_mime_types', array_values($sync['attachments']['allowed_mime_types']));
        $container->setParameter('nowo_task_board.sync.storage_enabled', $sync['attachments']['enabled'] && is_string($sync['attachment_storage']) && $sync['attachment_storage'] !== '');
        $container->setParameter('nowo_task_board.sync.max_tasks_per_run', $sync['max_tasks_per_run']);
        $container->setParameter('nowo_task_board.sync.max_pages_per_run', $sync['max_pages_per_run']);
        $container->setParameter('nowo_task_board.sync.max_archive_ratio', (float) $sync['max_archive_ratio']);
        $container->setParameter('nowo_task_board.sync.history_keep', $sync['history_keep']);
        $container->setParameter('nowo_task_board.sync.container_cache_ttl', $sync['container_cache_ttl']);
        $container->setParameter('nowo_task_board.sync.inline_slice_pages', $sync['inline_slice_pages']);
        $container->setParameter('nowo_task_board.sync.progress.poll_interval_ms', $sync['progress']['poll_interval_ms']);
        $container->setParameter('nowo_task_board.sync.badge.progress', (bool) $sync['badge']['progress']);
        $container->setParameter('nowo_task_board.sync.people.step', (bool) $sync['people']['step']);
        $container->setParameter('nowo_task_board.sync.people_bulk_former', (bool) $sync['people_bulk_former']);
        $container->setParameter('nowo_task_board.sync.unmatched_columns_choice', (bool) $sync['unmatched_columns_choice']);
        $container->setParameter('nowo_task_board.sync.people.page_size', (int) $sync['people']['page_size']);

        $loader->load('services_sync.yaml');
        $container->registerForAutoconfiguration(SyncTaskProviderInterface::class)->addTag('nowo_task_board.task_provider');
        $client = $sync['http']['client'];
        $container->setAlias('nowo_task_board.sync.api_client', is_string($client) && $client !== '' ? $client : 'nowo_task_board.sync.http_client');
        $container->setAlias(ProviderCredentialResolverInterface::class, $resolver);
        $container->setAlias(SyncActorResolverInterface::class, $this->serviceOr($sync['actor_resolver'], ConnectionCreatorActorResolver::class));
        // Since 1.24: the host's account checks for the default actor resolver (disabled / erased users never act).
        if (is_string($sync['actor_checker']) && $sync['actor_checker'] !== '') {
            $container->getDefinition(ConnectionCreatorActorResolver::class)->setArgument('$userChecker', new Reference($sync['actor_checker']));
        }
        $container->setAlias(SyncRunnerInterface::class, $this->serviceOr($sync['runner'], InlineSyncRunner::class));
        $container->setAlias(SyncLimitCheckerInterface::class, $this->serviceOr($sync['limit_checker'], NullSyncLimitChecker::class));
        $container->setAlias(InlineImageRewriterInterface::class, $this->serviceOr($sync['inline_image_rewriter'], MapInlineImageRewriter::class));
        $container->setAlias(TaskAttachmentStorageInterface::class, $this->serviceOr($sync['attachments']['enabled'] ? $sync['attachment_storage'] : null, NullTaskAttachmentStorage::class));
        if (class_exists(OutboundUrlGuard::class)) {
            $container->setDefinition(OutboundUrlGuardAdapter::class, (new Definition(OutboundUrlGuardAdapter::class))
                // The guard's own defaults (private and metadata addresses refused, DNS pinned).
                ->setArgument('$guard', new Definition(OutboundUrlGuard::class)));
            $container->setAlias(UrlGuardInterface::class, OutboundUrlGuardAdapter::class);
        }
        if ($sync['providers']['clickup']['enabled']) {
            $lookupCache = $sync['lookup']['cache_pool'];
            $container->setDefinition(ClickUpTaskProvider::class, (new Definition(ClickUpTaskProvider::class))
                ->setAutowired(true)
                ->setArgument('$attachmentHosts', array_values($sync['providers']['clickup']['attachment_hosts']))
                ->setArgument('$maxComments', $sync['max_comments_per_task'])
                ->setArgument('$maxDescriptionLength', $sync['max_description_length'])
                ->setArgument('$lookupCache', is_string($lookupCache) && $lookupCache !== '' ? new Reference($lookupCache) : null)
                ->setArgument('$includeArchived', (bool) $sync['import_archived'])
                ->setArgument('$includeMultiList', (bool) $sync['include_multi_list_tasks'])
                ->setArgument('$excludedFieldTypes', $sync['custom_fields']['exclude_types'])
                ->addTag('nowo_task_board.task_provider'));
        }
        $this->registerSyncPeople($container, $sync);
        $container->getDefinition(TaskBoardTwigExtension::class)
            ->setArgument('$connectionBadges', new Reference(ConnectionBadgeProvider::class));
        $container->getDefinition(TaskBoardRouteLoader::class)->setArgument('$syncEnabled', true);
    }

    /**
     * Since 1.25: the people directory, watchers, checklists, tracked time (through the host's sink) and the
     * pre-scans, from `sync.people`, `sync.watchers`, `sync.checklists`, `sync.time_entries` and `sync.list_as_tag`.
     *
     * @param array<string, mixed> $sync
     */
    private function registerSyncPeople(ContainerBuilder $container, array $sync): void
    {
        /** @var array{resolver: string|null, require_verified_email: bool, reassign_batch_size: int, inviter: string|null, candidate_limit: int, reapply_seconds: float, scan: array{max_task_pages: int, max_comment_tasks: int, time_window: bool}} $people */
        $people = $sync['people'];
        /** @var array{sink: string|null, window_days: int, max_assignees_per_call: int, max_entries_per_window: int, max_windows_per_run: int, fallback_max_tasks_per_run: int, max_calls_per_run: int} $time */
        $time = $sync['time_entries'];
        $container->getDefinition(DefaultSyncPersonResolver::class)->setArgument('$requireVerifiedEmail', $people['require_verified_email']);
        $container->setAlias(SyncPersonResolverInterface::class, $this->serviceOr($people['resolver'], DefaultSyncPersonResolver::class));
        $container->getDefinition(TaskSyncPeopleReassigner::class)->setArgument('$defaultBatchSize', $people['reassign_batch_size']);
        // Since 1.27.0 (TB-SYNC-2): the People page.
        $container->getDefinition(PeopleMappingService::class)
            ->setArgument('$inviter', is_string($people['inviter']) && $people['inviter'] !== '' ? new Reference($people['inviter']) : null)
            ->setArgument('$candidateLimit', $people['candidate_limit'])
            ->setArgument('$reapplySeconds', (float) $people['reapply_seconds']);
        $container->getDefinition(TaskSyncPeopleScanner::class)
            ->setArgument('$maxTaskPages', $people['scan']['max_task_pages'])
            ->setArgument('$maxCommentTasks', $people['scan']['max_comment_tasks'])
            ->setArgument('$timeWindow', $people['scan']['time_window'])
            ->setArgument('$maxAssigneesPerCall', $time['max_assignees_per_call']);
        $container->getDefinition(TimeEntryPhase::class)
            ->setArgument('$sink', is_string($time['sink']) && $time['sink'] !== '' ? new Reference($time['sink']) : null)
            ->setArgument('$windowDays', $time['window_days'])
            ->setArgument('$maxAssigneesPerCall', $time['max_assignees_per_call'])
            ->setArgument('$maxEntriesPerWindow', $time['max_entries_per_window'])
            ->setArgument('$maxWindowsPerRun', $time['max_windows_per_run'])
            ->setArgument('$fallbackMaxTasks', $time['fallback_max_tasks_per_run'])
            ->setArgument('$maxCallsPerRun', $time['max_calls_per_run']);
        $container->getDefinition(SyncChecklistWriter::class)
            ->setArgument('$maxItemsPerRun', (int) $sync['checklists']['max_items_per_run']);
        $container->getDefinition(SyncTaskWriter::class)
            ->setArgument('$listAsTag', (bool) $sync['list_as_tag'])
            ->setArgument('$checklistsEnabled', (bool) $sync['checklists']['enabled'])
            ->setArgument('$watchersEnabled', (bool) $sync['watchers']['enabled'])
            ->setArgument('$maxDetailCalls', (int) $sync['watchers']['max_detail_calls_per_run'])
            ->setArgument('$fieldsMode', (string) $sync['custom_fields']['mode'])
            ->setArgument('$importArchived', (bool) $sync['import_archived'])
            ->setArgument('$maxReplyCalls', (int) $sync['comments']['max_reply_calls_per_run'])
            ->setArgument('$fullSyncRereadsDetails', (bool) $sync['full_sync_rereads_details']);
        $container->getDefinition(SyncCustomFieldWriter::class)
            ->setArgument('$excludeTypes', $sync['custom_fields']['exclude_types']);
        $container->getDefinition(SyncTaskDetailsWriter::class)
            ->setArgument('$maxStatusCalls', (int) $sync['status_history']['max_calls_per_run']);
        $container->getDefinition(TaskBoardTwigExtension::class)
            ->setArgument('$syncPeople', new Reference(SyncPeopleViewProvider::class))
            ->setArgument('$syncFields', new Reference(SyncFieldViewProvider::class));
    }

    private function serviceOr(mixed $id, string $default): string
    {
        return is_string($id) && $id !== '' ? $id : $default;
    }

    /**
     * `tenancy.enabled`: decorates the router so links to the manage routes go to the tenant copies.
     */
    private function registerTenantUrlGenerator(ContainerBuilder $container, bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        // A host without a `router` service (console-only kernels) keeps no decorator.
        $container->setDefinition(TenantPathUrlGenerator::class, (new Definition(TenantPathUrlGenerator::class))
            ->setDecoratedService('router', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
            ->setArgument('$inner', new Reference(TenantPathUrlGenerator::class . '.inner'))
            ->setArgument('$tenantPaths', new Reference(TenantPaths::class))
            ->setArgument('$requestStack', new Reference('request_stack')));
    }

    /**
     * Aliases {@see TaskAssigneeProviderInterface} to `assignee_provider` when set (none: every user may be added).
     */
    private function registerAssigneeProvider(ContainerBuilder $container, mixed $providerId): void
    {
        if (is_string($providerId) && $providerId !== '') {
            $container->setAlias(TaskAssigneeProviderInterface::class, $providerId);
        }
    }

    /**
     * Since 1.15: {@see WeekStartResolverInterface} answers `gantt.week_start` ({@see ConfiguredWeekStartResolver}),
     * or the host's `gantt.week_start_resolver` service when set.
     *
     * @param array{week_start: string, week_start_resolver: string|null} $gantt
     */
    private function registerWeekStartResolver(ContainerBuilder $container, array $gantt): void
    {
        $container->setDefinition(ConfiguredWeekStartResolver::class, (new Definition(ConfiguredWeekStartResolver::class))
            ->setArgument('$weekStart', $gantt['week_start']));

        $resolverId = $gantt['week_start_resolver'];
        $container->setAlias(WeekStartResolverInterface::class, is_string($resolverId) && $resolverId !== '' ? $resolverId : ConfiguredWeekStartResolver::class);
    }

    /**
     * Since 1.16: {@see TaskMentionCandidatesResolverInterface} is the host's `comments.mention_resolver` when set, else
     * {@see DefaultTaskMentionCandidatesResolver}.
     */
    private function registerMentionResolver(ContainerBuilder $container, mixed $resolverId): void
    {
        $container->setAlias(TaskMentionCandidatesResolverInterface::class, is_string($resolverId) && $resolverId !== '' ? $resolverId : DefaultTaskMentionCandidatesResolver::class);
    }

    private function registerTimeTrackIntegration(ContainerBuilder $container, YamlFileLoader $loader): void
    {
        if (!interface_exists($this->timeTrackProviderInterface)) {
            return;
        }

        $loader->load('services_timetrack.yaml');
        $container->setAlias('nowo_task_board.task_provider', TaskBoardTaskProvider::class);
        $container->setAlias('nowo_task_board.team_context_provider', TaskBoardTeamContextProvider::class);

        // Since 1.25: totals of imported entries (TimeTrack >= 1.11); TimeTrack 1.10 has no such events.
        if (class_exists($this->timeTrackImportedEvent)) {
            $container->register(ImportedTimeAggregatorListener::class, ImportedTimeAggregatorListener::class)
                ->setAutowired(true)
                ->addTag('kernel.event_listener', ['event' => TimeTrackEvents::TIME_ENTRY_IMPORTED, 'method' => 'onImported'])
                ->addTag('kernel.event_listener', ['event' => TimeTrackEvents::TIME_ENTRIES_REMOVED, 'method' => 'onRemoved']);
        }

        // Since 1.26.1: manual and calendar entries (TimeTrack >= 1.14, contract §9.3). Older TimeTrack has neither the
        // event class nor the constant: only TIMER_STOP is aggregated, as before.
        if (class_exists($this->timeTrackEntryCreatedEvent) && defined(TimeTrackEvents::class . '::TIME_ENTRY_CREATED')) {
            $container->getDefinition(TimeSpentAggregatorListener::class)
                ->addTag('kernel.event_listener', ['event' => TimeTrackEvents::TIME_ENTRY_CREATED, 'method' => 'onTimeEntryCreated']);
        }
    }

    /** Since 2.2.0: whether a host set `api.hooks.idempotency_store` (then the bundle's table is not mapped). */
    private function apiStoreReplaced(ContainerBuilder $container): bool
    {
        // The last config that sets it wins, as in the merge (audit IA-L8).
        $store = null;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config['api'] ?? null) && is_array($config['api']['hooks'] ?? null) && array_key_exists('idempotency_store', $config['api']['hooks'])) {
                $store = $config['api']['hooks']['idempotency_store'];
            }
        }

        return is_string($store) && $store !== '';
    }

    private function syncEnabled(ContainerBuilder $container): bool
    {
        return $this->optionEnabled($container, 'sync');
    }

    /**
     * Whether any config file sets `<node>.enabled: true`, even if a later one sets it false. This keeps the 2.1 behaviour
     * for `sync` and `task_numbers`, so a layered config that ends at false never drops tables holding data. Deprecated:
     * 3.0 follows the merged value, as {@see self::apiEnabled()} already does.
     */
    private function optionEnabled(ContainerBuilder $container, string $node): bool
    {
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config[$node] ?? null) && ($config[$node]['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `api.enabled` ends up true, as the merged configuration will say: the last config that sets it wins, so
     * `enabled: true` in a base file and `false` in a `when@prod` override is off (audit IA-L8).
     */
    private function apiEnabled(ContainerBuilder $container): bool
    {
        $enabled = false;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config['api'] ?? null) && array_key_exists('enabled', $config['api'])) {
                $enabled = $config['api']['enabled'] === true;
            }
        }

        return $enabled;
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'assets' => [
                    'packages' => [
                        'nowo_task_board' => [
                            'base_path' => '/bundles/taskboard',
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TaskBoardBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }

        // Since 1.23: the sync tables are mapped only with sync.enabled.
        if ($container->hasExtension('doctrine') && $this->syncEnabled($container)) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TaskBoardSync' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/../Sync/Entity',
                            'prefix'    => 'Nowo\\TaskBoardBundle\\Sync\\Entity',
                            'alias'     => 'TaskBoardSync',
                        ],
                    ],
                ],
            ]);
        }

        // Since 2.2.0 (spec 213, audit IA-L3): the JSON-as-text function of the API's tag filter, only with api.enabled.
        if ($container->hasExtension('doctrine') && $this->apiEnabled($container)) {
            $container->prependExtensionConfig('doctrine', ['orm' => ['dql' => ['string_functions' => [JsonTextFunction::NAME => JsonTextFunction::class]]]]);
        }

        // Since 2.2.0 (spec 213): the idempotency table, only with api.enabled and the bundle's own store.
        if ($container->hasExtension('doctrine') && $this->apiEnabled($container) && !$this->apiStoreReplaced($container)) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TaskBoardApi' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/../Api/Entity',
                            'prefix'    => 'Nowo\\TaskBoardBundle\\Api\\Entity',
                            'alias'     => 'TaskBoardApi',
                        ],
                    ],
                ],
            ]);
        }

        // Since 2.3.0 (spec 215): the series and occurrence tables, only with recurrence.enabled.
        if ($container->hasExtension('doctrine') && $this->optionEnabled($container, 'recurrence')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TaskBoardRecurrence' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/../Recurrence/Entity',
                            'prefix'    => 'Nowo\\TaskBoardBundle\\Recurrence\\Entity',
                            'alias'     => 'TaskBoardRecurrence',
                        ],
                    ],
                ],
            ]);
        }

        // Since 2.1.0 (spec 214): the counter and the ledger are mapped only with task_numbers.enabled.
        if ($container->hasExtension('doctrine') && $this->optionEnabled($container, 'task_numbers')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'TaskBoardNumber' => [
                            'type'      => 'attribute',
                            'is_bundle' => false,
                            'dir'       => __DIR__ . '/../Number/Entity',
                            'prefix'    => 'Nowo\\TaskBoardBundle\\Number\\Entity',
                            'alias'     => 'TaskBoardNumber',
                        ],
                    ],
                ],
            ]);
        }

        $this->prependTiptapTaskProfile($container);
    }

    /**
     * TaskFormType renders the description with the Tiptap profile "task". When
     * nowo-tech/tiptap-editor-bundle is installed and the host did not define that profile, seed it
     * (simple variant, toolbar, 220px) so the task form works without extra configuration.
     * The host's default profile form_theme is reused so the editor matches the app's form layout.
     */
    private function prependTiptapTaskProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension(self::TIPTAP_EXTENSION)) {
            return;
        }

        $hostConfigs = $container->getExtensionConfig(self::TIPTAP_EXTENSION);
        $formTheme   = null;
        foreach ($hostConfigs as $hostConfig) {
            $profiles = $hostConfig['profiles'] ?? $hostConfig['configs'] ?? null;
            if (is_array($profiles)) {
                if (array_key_exists(self::TIPTAP_TASK_PROFILE, $profiles)) {
                    return;
                }
                $defaultName = $hostConfig['default_profile'] ?? $hostConfig['default_config'] ?? 'default';
                $theme       = is_string($defaultName) && is_array($profiles[$defaultName] ?? null)
                    ? ($profiles[$defaultName]['form_theme'] ?? null)
                    : null;
            } else {
                $theme = $hostConfig['form_theme'] ?? null;
            }
            if (is_string($theme) && $theme !== '') {
                $formTheme = $theme;
            }
        }

        $taskProfile = [
            'variant'    => 'simple',
            'toolbar'    => true,
            'min_height' => '220px',
        ];
        if ($formTheme !== null) {
            $taskProfile['form_theme'] = $formTheme;
        }

        $profiles = [self::TIPTAP_TASK_PROFILE => $taskProfile];
        if ($hostConfigs === []) {
            // Without any host config the Tiptap bundle has no profile at all; keep its "default" valid.
            $profiles['default'] = [];
        }

        $container->prependExtensionConfig(self::TIPTAP_EXTENSION, ['profiles' => $profiles]);
    }

    /**
     * When FormKit is installed, register the task_board profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('task_board', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'task_board' => [
                    'alias'              => 'task_board',
                    'translation_domain' => 'NowoTaskBoardBundle',
                    // Since 2.0.0: the import form printed its convention keys (task_import_form.file.help) as text.
                    'convention_requires_translation' => true,
                    'defaults'                        => [
                        'attr'     => ['class' => 'nowo-ui-input form-control'],
                        'row_attr' => ['class' => 'mb-2'],
                    ],
                    'field_types' => [
                        'checkbox' => [
                            'attr'     => ['class' => 'form-check-input'],
                            'row_attr' => ['class' => 'form-check mb-2'],
                        ],
                        'choice' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'entity' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'file' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                        'textarea' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    private function registerRepositories(ContainerBuilder $container, string $entityManagerName): void
    {
        $emRef = new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName));

        $repos = [
            TaskBoardRepositoryInterface::class      => DoctrineOrmTaskBoardRepository::class,
            BoardColumnRepositoryInterface::class    => DoctrineOrmBoardColumnRepository::class,
            TaskRepositoryInterface::class           => DoctrineOrmTaskRepository::class,
            TaskLinkRepositoryInterface::class       => DoctrineOrmTaskLinkRepository::class,
            TaskDependencyRepositoryInterface::class => DoctrineOrmTaskDependencyRepository::class,
            TaskMemberRepositoryInterface::class     => DoctrineOrmTaskMemberRepository::class,
            TaskDocumentRepositoryInterface::class   => DoctrineOrmTaskDocumentRepository::class,
            TaskTimeEntryRepositoryInterface::class  => DoctrineOrmTaskTimeEntryRepository::class,
            TeamMemberRepositoryInterface::class     => DoctrineOrmTeamMemberRepository::class,
            ColumnSetRepositoryInterface::class      => DoctrineOrmColumnSetRepository::class,
            // Since 1.16.
            TaskCommentRepositoryInterface::class => DoctrineOrmTaskCommentRepository::class,
        ];

        $flushingRepos = [
            DoctrineOrmTaskBoardRepository::class,
            DoctrineOrmBoardColumnRepository::class,
            DoctrineOrmTaskRepository::class,
            DoctrineOrmColumnSetRepository::class,
            DoctrineOrmTaskCommentRepository::class,
        ];

        foreach ($repos as $interface => $implementation) {
            $definition = (new Definition($implementation))
                ->setAutowired(false)
                ->setArgument('$entityManager', $emRef);

            if (in_array($implementation, $flushingRepos, true)) {
                $definition->setArgument('$managerRegistry', new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE));
            }

            $container->setDefinition($implementation, $definition);
            $container->setAlias($interface, $implementation);
        }
    }

    private function registerMetadataListener(ContainerBuilder $container, string $prefix, string $userClass): void
    {
        $container->setDefinition(TaskBoardMetadataListener::class, (new Definition(TaskBoardMetadataListener::class))
            ->setAutowired(false)
            ->setArgument('$tasksTableName', $prefix . '_tasks')
            ->setArgument('$boardsTableName', $prefix . '_boards')
            ->setArgument('$teamsTableName', $prefix . '_teams')
            ->setArgument('$teamMembersTableName', $prefix . '_team_members')
            ->setArgument('$userClass', $userClass)
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
    }

    /** @param array<string, mixed> $security */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        if ($security['allow_unauthenticated']) {
            $accessCheckerId = 'nowo_task_board.access_checker.allow_all';
            $container->setDefinition($accessCheckerId, new Definition(AllowAllTaskBoardAccessChecker::class));
            $container->setAlias(TaskBoardAccessCheckerInterface::class, $accessCheckerId);

            return;
        }

        $accessCheckerId = $security['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_task_board.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableTaskBoardAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$accessRoles', $security['access_roles'])
                ->setArgument('$createRoles', $security['create_roles'])
                ->setArgument('$listRoles', $security['list_roles']));
        }

        $container->setAlias(TaskBoardAccessCheckerInterface::class, $accessCheckerId);
    }

    /** @param array<string, mixed> $security */
    private function registerResourceAccessChecker(ContainerBuilder $container, array $security): void
    {
        $container->setDefinition(TaskBoardResourceAccessChecker::class, (new Definition(TaskBoardResourceAccessChecker::class))
            ->setArgument('$teamMemberRepository', new Reference(TeamMemberRepositoryInterface::class))
            ->setArgument('$teamMembershipResolver', new Reference(TaskBoardTeamMembershipResolverInterface::class))
            ->setArgument('$authorizationChecker', new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$eventDispatcher', new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$manageRoles', $security['manage_roles'])
            // Demo/dev mode (allow_unauthenticated) keeps the historical "every board visible" behaviour.
            ->setArgument('$scopingEnabled', $security['resource_scoping'] && !$security['allow_unauthenticated']));
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }

    /**
     * Aliases the tenant resolver: `tenancy.resolver`, else the slug-is-the-tenant default.
     */
    private function registerTenantResolver(ContainerBuilder $container, mixed $resolverId): void
    {
        if (is_string($resolverId) && $resolverId !== '') {
            $container->setAlias(TaskBoardTenantResolverInterface::class, $resolverId);

            return;
        }

        $container->setDefinition('nowo_task_board.tenant_resolver.slug', new Definition(SlugTaskBoardTenantResolver::class));
        $container->setAlias(TaskBoardTenantResolverInterface::class, 'nowo_task_board.tenant_resolver.slug');
    }

    private function registerTeamResolver(ContainerBuilder $container, mixed $resolverId): void
    {
        if (is_string($resolverId) && $resolverId !== '') {
            $container->setAlias(TaskBoardTeamMembershipResolverInterface::class, $resolverId);

            return;
        }

        $container->setDefinition('nowo_task_board.team_resolver.null', new Definition(NullTaskBoardTeamMembershipResolver::class));
        $container->setAlias(TaskBoardTeamMembershipResolverInterface::class, 'nowo_task_board.team_resolver.null');
    }
}
