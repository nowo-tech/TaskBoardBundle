<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support;

use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function getenv;
use function is_array;

/**
 * Since 2.15.0: TASK_BOARD_TEST_PRIVATE_EM=1 runs the functional suite with `database.private_entity_manager: true`
 * (unless a test sets the option itself). With the option on, the kernels expose the private manager as the public
 * `app.task_board_em`.
 */
final class PrivateEntityManagerReplay
{
    public static function enabled(): bool
    {
        return getenv('TASK_BOARD_TEST_PRIVATE_EM') === '1';
    }

    /**
     * @param array<mixed> $taskBoard
     *
     * @return array<mixed>
     */
    public static function configure(array $taskBoard): array
    {
        if (!self::enabled()) {
            return $taskBoard;
        }
        $database = is_array($taskBoard['database'] ?? null) ? $taskBoard['database'] : [];
        $database += ['private_entity_manager' => true];
        $taskBoard['database'] = $database;

        return $taskBoard;
    }

    /**
     * @param array<mixed> $taskBoard
     */
    public static function alias(ContainerConfigurator $container, array $taskBoard): void
    {
        $database = is_array($taskBoard['database'] ?? null) ? $taskBoard['database'] : [];
        if (($database['private_entity_manager'] ?? null) === true) {
            $container->services()->alias('app.task_board_em', TaskBoardEntityManager::class)->public();
        }
    }
}
