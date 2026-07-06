<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
        ]]);

        self::assertSame('task_board_', $config['table_prefix']);
        self::assertSame('/tools/task-board', $config['routes']['index']['path']);
    }
}
