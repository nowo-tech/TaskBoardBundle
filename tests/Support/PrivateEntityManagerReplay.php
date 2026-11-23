<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support;

use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function getenv;
use function is_array;

/**
 * The test kernels' access to the bundle's private entity manager (since 3.0.0 the only mode): they expose it as the
 * public `app.task_board_em`, the manager the fixtures are written through. TASK_BOARD_TEST_XML_MAPPING=1 runs the
 * suite with `database.mapping_type: xml` (unless a test sets the option itself).
 */
final class PrivateEntityManagerReplay
{
    /**
     * @param array<mixed> $taskBoard
     *
     * @return array<mixed>
     */
    public static function configure(array $taskBoard): array
    {
        if (getenv('TASK_BOARD_TEST_XML_MAPPING') !== '1') {
            return $taskBoard;
        }
        $database = is_array($taskBoard['database'] ?? null) ? $taskBoard['database'] : [];
        $database += ['mapping_type' => 'xml'];
        $taskBoard['database'] = $database;

        return $taskBoard;
    }

    public static function alias(ContainerConfigurator $container): void
    {
        $container->services()->alias('app.task_board_em', TaskBoardEntityManager::class)->public();
    }
}
