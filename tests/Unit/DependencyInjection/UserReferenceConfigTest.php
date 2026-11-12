<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use LogicException;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardMetadataListener;
use Nowo\TaskBoardBundle\Doctrine\Type\TaskBoardUserRefType;
use Nowo\TaskBoardBundle\User\TaskBoardUserDirectoryInterface;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 2): `database.user_reference`, `user_directory` and `database.mapping_type`.
 */
final class UserReferenceConfigTest extends TestCase
{
    public function testTheDefaultsKeepTheRelationAndTheAttributes(): void
    {
        $container = $this->load([]);

        self::assertSame('entity', $container->getParameter('nowo_task_board.user_reference'));
        self::assertEquals(['entity', null], $container->getDefinition(TaskBoardUsers::class)->getArguments());
        self::assertSame('entity', $container->getDefinition(TaskBoardMetadataListener::class)->getArgument('$userReference'));
        self::assertFalse($container->hasAlias(TaskBoardUserDirectoryInterface::class));

        $prepended = $this->prepend([]);
        self::assertSame(['type' => 'attribute', 'is_bundle' => true], $prepended['orm']['mappings']['TaskBoardBundle']);
        self::assertArrayNotHasKey('dbal', $prepended);
    }

    public function testTheIdModeNeedsTheDirectory(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs user_directory');

        $this->load(['database' => ['user_reference' => 'id']]);
    }

    public function testTheIdModeWiresTheDirectoryAndTheColumnType(): void
    {
        $container = $this->load(['database' => ['user_reference' => 'id'], 'user_directory' => 'app.users']);

        self::assertSame('id', $container->getParameter('nowo_task_board.user_reference'));
        self::assertEquals(['id', new Reference('app.users')], $container->getDefinition(TaskBoardUsers::class)->getArguments());
        self::assertSame([['method' => 'reset']], $container->getDefinition(TaskBoardUsers::class)->getTag('kernel.reset'));
        self::assertSame('app.users', (string) $container->getAlias(TaskBoardUserDirectoryInterface::class));
        self::assertSame('id', $container->getDefinition(TaskBoardMetadataListener::class)->getArgument('$userReference'));

        $prepended = $this->prepend(['database' => ['user_reference' => 'id']]);
        self::assertSame([TaskBoardUserRefType::NAME => TaskBoardUserRefType::class], $prepended['dbal']['types']);
    }

    public function testTheXmlMappingCoversEveryEnabledGroup(): void
    {
        $prepended = $this->prepend(['database' => ['mapping_type' => 'xml'], 'sync' => ['enabled' => true], 'recurrence' => ['enabled' => true], 'task_numbers' => ['enabled' => true], 'api' => ['enabled' => true]]);
        $mappings  = $prepended['orm']['mappings'];

        foreach (['TaskBoardBundle' => 'core', 'TaskBoardSync' => 'sync', 'TaskBoardApi' => 'api', 'TaskBoardRecurrence' => 'recurrence', 'TaskBoardNumber' => 'number'] as $name => $group) {
            self::assertSame('xml', $mappings[$name]['type'], $name);
            self::assertFalse($mappings[$name]['is_bundle']);
            self::assertStringEndsWith('/Resources/config/doctrine-xml/' . $group, $mappings[$name]['dir']);
            self::assertDirectoryExists($mappings[$name]['dir']);
            self::assertSame($name, $mappings[$name]['alias']);
        }

        $layered = $this->prepend(['database' => ['mapping_type' => 'xml']], ['database' => ['mapping_type' => 'attribute']]);
        self::assertSame('attribute', $layered['orm']['mappings']['TaskBoardBundle']['type'], 'the last config wins');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class] + $config], $container);

        return $container;
    }

    /**
     * The merged `doctrine` configuration the bundle prepends.
     *
     * @param array<string, mixed> ...$configs
     *
     * @return array<string, mixed>
     */
    private function prepend(array ...$configs): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });
        $container->registerExtension(new TaskBoardExtension());
        foreach ($configs as $config) {
            $container->loadFromExtension('nowo_task_board', ['user_class' => User::class] + $config);
        }
        (new TaskBoardExtension())->prepend($container);
        $merged = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $merged = array_replace_recursive($config, $merged);
        }

        return $merged;
    }
}
