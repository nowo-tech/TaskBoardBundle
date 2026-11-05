<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection;

use LogicException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTeamContextProvider;
use Nowo\TaskBoardBundle\Comment\DefaultTaskMentionCandidatesResolver;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Form\TaskFormType;
use Nowo\TaskBoardBundle\Gantt\ConfiguredWeekStartResolver;
use Nowo\TaskBoardBundle\Gantt\WeekStartResolverInterface;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
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
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleReassigner;
use Nowo\TaskBoardBundle\Sync\People\TaskSyncPeopleScanner;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpTaskProvider;
use Nowo\TaskBoardBundle\Sync\Provider\TaskProviderInterface as SyncTaskProviderInterface;
use Nowo\TaskBoardBundle\Sync\SyncTaskWriter;
use Nowo\TaskBoardBundle\Sync\Time\TimeEntryPhase;
use Nowo\TaskBoardBundle\Sync\View\ConnectionBadgeProvider;
use Nowo\TaskBoardBundle\Sync\View\SyncPeopleViewProvider;
use Nowo\TaskBoardBundle\Tenancy\SlugTaskBoardTenantResolver;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\TaskBoardBundle\View\NullTaskBoardDefaultViewResolver;
use Nowo\TaskBoardBundle\View\TaskBoardDefaultViewResolverInterface;
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
     */
    public function __construct(
        private readonly string $timeTrackProviderInterface = TaskProviderInterface::class,
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
        $container->setParameter('nowo_task_board.templates', $config['templates']);
        $container->setParameter('nowo_task_board.templates.layout', $config['templates']['layout']);
        $container->setParameter('nowo_task_board.templates.css_framework', $config['templates']['css_framework']);
        $container->setParameter('nowo_task_board.templates.filters_collapse', $config['templates']['filters_collapse']);
        $container->setParameter('nowo_task_board.templates.filters_form_class', $config['templates']['filters_form_class']);
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
        /** @var array{resolver: string|null, require_verified_email: bool, reassign_batch_size: int, scan: array{max_task_pages: int, max_comment_tasks: int, time_window: bool}} $people */
        $people = $sync['people'];
        /** @var array{sink: string|null, window_days: int, max_assignees_per_call: int, max_entries_per_window: int, max_windows_per_run: int, fallback_max_tasks_per_run: int} $time */
        $time = $sync['time_entries'];
        $container->getDefinition(DefaultSyncPersonResolver::class)->setArgument('$requireVerifiedEmail', $people['require_verified_email']);
        $container->setAlias(SyncPersonResolverInterface::class, $this->serviceOr($people['resolver'], DefaultSyncPersonResolver::class));
        $container->getDefinition(TaskSyncPeopleReassigner::class)->setArgument('$defaultBatchSize', $people['reassign_batch_size']);
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
            ->setArgument('$fallbackMaxTasks', $time['fallback_max_tasks_per_run']);
        $container->getDefinition(SyncTaskWriter::class)
            ->setArgument('$listAsTag', (bool) $sync['list_as_tag'])
            ->setArgument('$checklistsEnabled', (bool) $sync['checklists']['enabled'])
            ->setArgument('$watchersEnabled', (bool) $sync['watchers']['enabled'])
            ->setArgument('$maxDetailCalls', (int) $sync['watchers']['max_detail_calls_per_run']);
        $container->getDefinition(TaskBoardTwigExtension::class)
            ->setArgument('$syncPeople', new Reference(SyncPeopleViewProvider::class));
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
    }

    private function syncEnabled(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (is_array($config['sync'] ?? null) && ($config['sync']['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
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

        $this->prependUiKitDefaults($container);
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
                    'defaults'           => [
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

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from templates
     * so kit macros resolve the same stack. Does not override keys the host already set.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        $config    = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        $defaults  = [];

        if (!$hostHasCssFramework) {
            $fw                        = (string) ($templates['css_framework'] ?? 'tabler');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $fwForIcons           = (string) ($defaults['css_framework'] ?? $templates['css_framework'] ?? 'tabler');
            $defaults['icon_set'] = $fwForIcons === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
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
