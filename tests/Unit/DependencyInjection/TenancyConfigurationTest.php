<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Service\TaskBoardCreator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class TenancyConfigurationTest extends TestCase
{
    public function testDefaultsKeepTheBehaviourOf19(): void
    {
        $config = $this->process([]);

        self::assertFalse($config['tenancy']['enabled']);
        self::assertSame([], $config['tenancy']['tenant_paths']);
        self::assertFalse($config['tenancy']['single_board']);
        self::assertNull($config['tenancy']['resolver']);
        self::assertNull($config['assignee_provider']);
        self::assertSame(TaskBoardCreator::DEFAULT_COLUMNS, $config['board']['default_columns']);
        self::assertFalse($config['board']['top_level_only']);
    }

    public function testTenantPaths(): void
    {
        $config = $this->process([
            'tenancy' => [
                'enabled'      => true,
                'single_board' => true,
                'resolver'     => 'app.tenant_resolver',
                'tenant_paths' => [['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_', 'slug_requirement' => '\d+']],
            ],
            'assignee_provider' => 'app.assignees',
            'board'             => ['default_columns' => [['name' => 'Backlog'], ['name' => 'Done', 'color' => '#22c55e']], 'top_level_only' => true],
        ]);

        self::assertSame([['path' => '/projects/{tenantSlug}/tasks', 'name_prefix' => 'app_project_', 'slug_requirement' => '\d+', 'slug_prefix' => '']], $config['tenancy']['tenant_paths']);
        self::assertSame([['name' => 'Backlog', 'color' => null, 'icon' => null], ['name' => 'Done', 'color' => '#22c55e', 'icon' => null]], $config['board']['default_columns']);
        self::assertSame('app.assignees', $config['assignee_provider']);
        self::assertTrue($config['board']['top_level_only']);
    }

    public function testTenancyNeedsATenantPath(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('tenant_paths');

        $this->process(['tenancy' => ['enabled' => true]]);
    }

    public function testATenantPathNeedsThePlaceholder(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('{tenantSlug}');

        $this->process(['tenancy' => ['enabled' => true, 'tenant_paths' => [['path' => '/projects/tasks', 'name_prefix' => 'app_']]]]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [['user_class' => User::class] + $config]);
    }
}
