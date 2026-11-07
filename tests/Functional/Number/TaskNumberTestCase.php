<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Number;

use Nowo\TaskBoardBundle\Number\TaskNumberLedger;
use Nowo\TaskBoardBundle\Tests\Functional\FunctionalTestCase;

/**
 * Since 2.1.0 (spec 214): the functional kernel with `task_numbers.enabled` and tenant paths.
 */
abstract class TaskNumberTestCase extends FunctionalTestCase
{
    public const CONFIG = [
        'task_numbers' => ['enabled' => true],
        'tenancy'      => ['enabled' => true, 'tenant_paths' => [['path' => '/t/{tenantSlug}/tasks', 'name_prefix' => 't_']]],
    ];

    protected function setUp(): void
    {
        static::$taskBoardConfig = static::$taskBoardConfig !== [] ? static::$taskBoardConfig : static::CONFIG;
        parent::setUp();
    }

    protected function reconfigure(array $config): void
    {
        self::ensureKernelShutdown();
        static::$taskBoardConfig = $config;
        $this->setUp();
    }

    protected function ledger(): TaskNumberLedger
    {
        $ledger = static::getContainer()->get(TaskNumberLedger::class);
        self::assertInstanceOf(TaskNumberLedger::class, $ledger);

        return $ledger;
    }

    protected function enforceForeignKeys(): void
    {
        if (str_contains($this->em->getConnection()->getDatabasePlatform()::class, 'SQLite')) {
            $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        }
    }
}
