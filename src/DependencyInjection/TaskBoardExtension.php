<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection;

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
use Nowo\TaskBoardBundle\Security\ConfigurableTaskBoardAccessChecker;
use Nowo\TaskBoardBundle\Security\NullTaskBoardTeamMembershipResolver;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardTeamMembershipResolverInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

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
        $container->setParameter('nowo_task_board.firewall', $config['firewall']);
        $container->setParameter('nowo_task_board.security', $config['security']);

        $this->registerRepositories($container, $emName);
        $this->registerMetadataListener($container, $prefix, $config['user_class']);
        $this->registerAccessChecker($container, $config['security']);
        $this->registerTeamResolver($container, $config['team_membership_resolver'] ?? null);

        $container->setAlias('nowo_task_board.task_provider', TaskBoardTaskProvider::class);
        $container->setAlias('nowo_task_board.team_context_provider', TaskBoardTeamContextProvider::class);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'assets' => [
                    'packages' => [
                        'nowo_task_board' => [
                            'base_path' => '/bundles/nowotaskboard',
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
