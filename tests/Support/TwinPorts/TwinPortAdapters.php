<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function getenv;
use function is_array;
use function is_string;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Twin replay: the adapters of this namespace implement ONLY the 2.15 framework-free twin of a host port and delegate to
 * a test stub written against the deprecated interface, after finding the entity behind each snapshot. With
 * TASK_BOARD_TEST_PORTS=twin the functional kernels point every port option at these adapters, so the whole functional
 * suite runs through the bundle's gates and must give the same results.
 */
final class TwinPortAdapters
{
    /**
     * Port option values (service ids) of the test kernels => the twin adapter registered as `<id>.twin`.
     *
     * @var array<string, class-string>
     */
    public const ADAPTERS = [
        'app.assignee_provider'           => TwinAssigneePolicy::class,
        'app.cause_labeler'               => TwinCauseText::class,
        'app.default_view'                => TwinDefaultViewPolicy::class,
        'app.first_board'                 => TwinFirstBoardProvisioning::class,
        'app.all_targets'                 => TwinTargetIds::class,
        'app.sync_credentials'            => TwinCredentialSource::class,
        'app.sync_storage'                => TwinFileStorage::class,
        'app.sync_limits'                 => TwinQuotaPolicy::class,
        'app.time_sink'                   => TwinTimeReceiver::class,
        'app.time_sink_ownerless'         => TwinOwnerlessTimeReceiver::class,
        'app.people_resolver'             => TwinPersonDirectory::class,
        'app.people_resolver_attribution' => TwinAttributionPersonDirectory::class,
        'app.people_inviter'              => TwinPeopleInvitation::class,
        'app.import_users'                => TwinImportUsers::class,
        'app.api_host'                    => TwinApiHost::class,
    ];

    /**
     * Registers `<id>.twin` for every adapter whose stub the kernel defines.
     */
    public static function register(ContainerConfigurator $container): void
    {
        $services = $container->services();
        $services->set(TwinLookup::class)->autowire();
        foreach (self::ADAPTERS as $id => $class) {
            $services->set($id . '.twin', $class)->autowire()->arg('$legacy', service($id));
        }
    }

    public static function enabled(): bool
    {
        return getenv('TASK_BOARD_TEST_PORTS') === 'twin';
    }

    /**
     * Rewrites every port option value of a `nowo_task_board` configuration to the twin adapter's id.
     *
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    public static function rewrite(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = self::rewrite($value);
            } elseif (is_string($value) && isset(self::ADAPTERS[$value])) {
                $config[$key] = $value . '.twin';
            }
        }

        return $config;
    }
}
