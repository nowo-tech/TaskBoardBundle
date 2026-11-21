<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardEntityManager;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardHostFlushProbe;
use Nowo\TaskBoardBundle\Doctrine\TaskBoardManagerRegistry;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use Nowo\TransactionDoctrine\HostFlushProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_last;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Since 2.15.0: `database.private_entity_manager`. null keeps the 2.14 wiring, false too with a deprecation, true
 * registers the private manager, its registry and (DBAL 4) the rollback middleware, and moves every bundle service onto
 * them; host services are never touched.
 */
final class PrivateEntityManagerConfigTest extends TestCase
{
    public function testNullKeepsThe214Wiring(): void
    {
        $container = $this->loadRecording([], $deprecations);

        self::assertFalse($container->getParameter(PrivateEntityManagerReferencesPass::PARAMETER));
        self::assertSame('default', $container->getParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER));
        self::assertFalse($container->hasDefinition(TaskBoardEntityManager::class));
        self::assertFalse($container->hasDefinition(TaskBoardManagerRegistry::class));
    }

    /**
     * Since 2.28.0 (REQ-ARCH-001 rule 6 deprecation layer): unset is deprecated (once per container build), naming the
     * manager, the 3.0 default and the line that opts in now; the host-flush probe is registered.
     */
    public function testUnsetIsDeprecatedAndRegistersTheHostFlushProbe(): void
    {
        $container = $this->loadRecording(['entity_manager' => 'tasks'], $deprecations);

        self::assertSame(['Since nowo-tech/task-board-bundle 2.28: ' . TaskBoardExtension::unsetPrivateEntityManagerNotice('tasks')], $deprecations);
        self::assertSame('Since nowo-tech/task-board-bundle 2.28: Not setting "nowo_task_board.database.private_entity_manager" is deprecated: every TaskBoard write then flushes your entity manager "tasks", including what your application left pending in it. In 3.0 the option is removed and the private entity manager is the only mode (as if true): TaskBoard writes never flush, clear or close your manager. Opt in now with "nowo_task_board: { database: { private_entity_manager: true } }" once the "were flushed by NowoTaskBoardBundle" deprecations are gone (see UPGRADING.md "To 2.28.0").', $deprecations[0]);
        $this->assertHostFlushProbe($container);
    }

    public function testFalseIsDeprecated(): void
    {
        $container = $this->loadRecording(['private_entity_manager' => false], $deprecations);

        self::assertFalse($container->hasDefinition(TaskBoardEntityManager::class));
        self::assertCount(1, $deprecations);
        self::assertStringContainsString('"nowo_task_board.database.private_entity_manager: false" is deprecated', $deprecations[0]);
        $this->assertHostFlushProbe($container);
    }

    /** Since 2.28.0: true gives no notice and no probe (the private manager never flushes the application's). */
    public function testTrueIsNotDeprecatedAndHasNoProbe(): void
    {
        $container = $this->loadRecording(['private_entity_manager' => true], $deprecations);

        self::assertSame([], $deprecations);
        self::assertFalse($container->hasDefinition(TaskBoardHostFlushProbe::class));
    }

    public function testTrueRegistersThePrivateManagerOnTheSource(): void
    {
        $container = $this->load(['private_entity_manager' => true, 'entity_manager' => 'tasks']);

        self::assertTrue($container->getParameter(PrivateEntityManagerReferencesPass::PARAMETER));
        self::assertSame('tasks', $container->getParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER));
        $manager = $container->getDefinition(TaskBoardEntityManager::class);
        self::assertEquals([new Reference('doctrine'), 'tasks', new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)], $manager->getArguments());
        self::assertSame([['method' => 'reset']], $manager->getTag('kernel.reset'));
        self::assertSame([['event' => 'postLoad', 'method' => 'postLoad', 'lazy' => true, 'entity_manager' => 'tasks']], $manager->getTag('doctrine.event_listener'));
        self::assertEquals([new Reference('doctrine'), new Reference(TaskBoardEntityManager::class), 'tasks'], $container->getDefinition(TaskBoardManagerRegistry::class)->getArguments());
        self::assertTrue($container->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('doctrine.middleware'));

        $state = $container->getDefinition(TaskBoardRequestBoundarySubscriber::class)->getArgument('$state');
        self::assertInstanceOf(IteratorArgument::class, $state);
        self::assertEquals(new Reference(TaskBoardEntityManager::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE), $state->getValues()[array_key_last($state->getValues())], 'dropped at every boundary, after the memos');
    }

    public function testTheReferencesPassMovesOnlyBundleServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(PrivateEntityManagerReferencesPass::PARAMETER, true);
        $container->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, 'default');
        $container->register('doctrine.orm.default_entity_manager', EntityManagerInterface::class);
        $container->register('doctrine.orm.other_entity_manager', EntityManagerInterface::class);
        $container->setAlias('doctrine.orm.entity_manager', 'doctrine.orm.default_entity_manager');
        $container->setAlias(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
        $container->register('doctrine', ManagerRegistry::class);
        $container->setAlias(ManagerRegistry::class, 'doctrine');
        $container->register(TaskBoardEntityManager::class, TaskBoardEntityManager::class)->setArguments([new Reference('doctrine'), 'default']);
        $container->register(TaskBoardManagerRegistry::class, TaskBoardManagerRegistry::class)->setArguments([new Reference('doctrine'), new Reference(TaskBoardEntityManager::class), 'default']);
        $bundle = $container->register(TaskBoardEraser::class, TaskBoardEraser::class)
            ->setArguments([
                new Reference(EntityManagerInterface::class),
                [new Reference('doctrine.orm.entity_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE)],
                new IteratorArgument([new Reference(ManagerRegistry::class)]),
                (new Definition('Nowo\\TaskBoardBundle\\Inline'))->setArguments([new Reference('doctrine.orm.default_entity_manager')]),
                new Reference('doctrine.orm.other_entity_manager'),
                'plain',
            ])
            ->setProperty('registry', new Reference('doctrine'))
            ->addMethodCall('setManager', [new Reference('doctrine.orm.default_entity_manager')]);
        $container->register('Nowo\\TaskBoardBundle\\ByIdOnly')->setArguments([new Reference(EntityManagerInterface::class)]);
        $host = $container->register('app.host_service', 'App\\HostService')->setArguments([new Reference(EntityManagerInterface::class), new Reference('doctrine')]);

        (new PrivateEntityManagerReferencesPass())->process($container);

        $arguments = $bundle->getArguments();
        self::assertEquals(new Reference(TaskBoardEntityManager::class), $arguments[0]);
        self::assertEquals([new Reference(TaskBoardEntityManager::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)], $arguments[1]);
        self::assertEquals([new Reference(TaskBoardManagerRegistry::class)], $arguments[2]->getValues());
        self::assertEquals([new Reference(TaskBoardEntityManager::class)], $arguments[3]->getArguments(), 'inline definitions too');
        self::assertEquals(new Reference('doctrine.orm.other_entity_manager'), $arguments[4], 'another manager stays');
        self::assertSame('plain', $arguments[5]);
        self::assertEquals(new Reference(TaskBoardManagerRegistry::class), $bundle->getProperties()['registry']);
        self::assertEquals([['setManager', [new Reference(TaskBoardEntityManager::class)]]], $bundle->getMethodCalls());
        self::assertEquals([new Reference(TaskBoardEntityManager::class)], $container->getDefinition('Nowo\\TaskBoardBundle\\ByIdOnly')->getArguments(), 'a definition without a class is matched by its id');
        self::assertEquals([new Reference(EntityManagerInterface::class), new Reference('doctrine')], $host->getArguments(), 'host services keep the application manager');
        self::assertEquals([new Reference('doctrine'), 'default'], $container->getDefinition(TaskBoardEntityManager::class)->getArguments(), 'the private manager keeps the application registry');
    }

    public function testTheReferencesPassDoesNothingWithoutTheOption(): void
    {
        $container  = new ContainerBuilder();
        $definition = $container->register(TaskBoardEraser::class, TaskBoardEraser::class)->setArguments([new Reference('doctrine.orm.default_entity_manager')]);
        (new PrivateEntityManagerReferencesPass())->process($container);
        $container->setParameter(PrivateEntityManagerReferencesPass::PARAMETER, false);
        (new PrivateEntityManagerReferencesPass())->process($container);

        self::assertEquals([new Reference('doctrine.orm.default_entity_manager')], $definition->getArguments());
    }

    private function assertHostFlushProbe(ContainerBuilder $container): void
    {
        $probe = $container->getDefinition(TaskBoardHostFlushProbe::class);
        self::assertEquals([new Reference(HostFlushProbe::SERVICE_ID, ContainerInterface::NULL_ON_INVALID_REFERENCE)], $probe->getArguments());
        self::assertSame([['event' => 'preFlush', 'method' => 'preFlush']], $probe->getTag('doctrine.event_listener'), 'every manager: a TaskBoard flush of any of them is probed');
        self::assertSame([['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 4096]], $probe->getTag('kernel.event_listener'));
        self::assertSame([['method' => 'reset']], $probe->getTag('kernel.reset'));
        $state = $container->getDefinition(TaskBoardRequestBoundarySubscriber::class)->getArgument('$state');
        self::assertInstanceOf(IteratorArgument::class, $state);
        self::assertContainsEquals(new Reference(TaskBoardHostFlushProbe::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE), $state->getValues(), 'reset at every boundary');
    }

    /**
     * @param array<string, mixed> $database
     * @param list<string>|null $deprecations the deprecations the load triggered
     *
     * @param-out list<string> $deprecations
     */
    private function loadRecording(array $database, ?array &$deprecations): ContainerBuilder
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            return $this->load($database);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $database
     */
    private function load(array $database): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new TaskBoardExtension())->load([['user_class' => User::class, 'database' => $database]], $container);

        return $container;
    }
}
