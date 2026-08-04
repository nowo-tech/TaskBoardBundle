<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection;

use LogicException;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTaskProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TaskBoardTeamContextProvider;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmBoardColumnRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskBoardRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDependencyRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskDocumentRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskLinkRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskMemberRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTaskTimeEntryRepository;
use Nowo\TaskBoardBundle\Repository\DoctrineOrmTeamMemberRepository;
use Nowo\TaskBoardBundle\Repository\TaskBoardRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskDependencyRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskDocumentRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskLinkRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TaskTimeEntryRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\TeamMemberRepositoryInterface;
use Nowo\TaskBoardBundle\Security\AllowAllTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Nowo\TimeTrackBundle\Integration\TaskProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Loads bundle configuration and registers services.
 */
final class TaskBoardExtension extends Extension implements PrependExtensionInterface
{
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
        $container->setParameter('nowo_task_board.firewall', $config['firewall']);
        $container->setParameter('nowo_task_board.security', $config['security']);
        $container->setParameter('nowo_task_board.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);

        if (
            !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('TaskBoardBundle manage UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $this->registerRepositories($container, $emName);
        $this->registerMetadataListener($container, $prefix, $config['user_class']);
        $this->registerAccessChecker($container, $config['security']);
        $this->registerTeamResolver($container, $config['team_membership_resolver'] ?? null);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
        $this->registerTimeTrackIntegration($container, $loader);
    }

    private function registerTimeTrackIntegration(ContainerBuilder $container, YamlFileLoader $loader): void
    {
        if (!interface_exists(TaskProviderInterface::class)) {
            return;
        }

        $loader->load('services_timetrack.yaml');
        $container->setAlias('nowo_task_board.task_provider', TaskBoardTaskProvider::class);
        $container->setAlias('nowo_task_board.team_context_provider', TaskBoardTeamContextProvider::class);
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

        $this->prependUiKitDefaults($container);
    }

    /**
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from templates
     * so kit macros resolve the same stack. Does not override keys the host already set.
     */

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

    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
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
            $fw = (string) ($templates['css_framework'] ?? 'tabler');
            $defaults['css_framework'] = $fw === 'bootstrap' ? 'bootstrap5' : $fw;
        }
        if (!$hostHasIconSet) {
            $fwForIcons = (string) ($defaults['css_framework'] ?? $templates['css_framework'] ?? 'tabler');
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
        ];

        foreach ($repos as $interface => $implementation) {
            $container->setDefinition($implementation, (new Definition($implementation))
                ->setAutowired(false)
                ->setArgument('$entityManager', $emRef));
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
