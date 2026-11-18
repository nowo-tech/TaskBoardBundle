<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection;

use Nowo\TaskBoardBundle\Api\Security\TaskApiConflictResponseFormatter;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimerStarter;
use Nowo\TaskBoardBundle\DependencyInjection\TaskBoardExtension;
use Nowo\TaskBoardBundle\Menu\TaskTimerStarterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 2.22.0: the wiring of the context menus option, the TimeTrack timer of the task menu, the Task API's
 * lock-conflict formatter (only when transaction-doctrine's interface exists) and the shared conflict policy.
 */
final class MenusAndConflictsExtensionTest extends TestCase
{
    public function testTheContextMenusOptionFollowsTheThemeByDefault(): void
    {
        self::assertNull($this->load([])->getParameter('nowo_task_board.web_ui.context_menus'));
        self::assertTrue($this->load(['web_ui' => ['context_menus' => true]])->getParameter('nowo_task_board.web_ui.context_menus'));
        self::assertFalse($this->load(['web_ui' => ['context_menus' => false]])->getParameter('nowo_task_board.web_ui.context_menus'));
    }

    public function testTheContextMenusOptionIsABoolOrNull(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('web_ui.context_menus');

        $this->load(['web_ui' => ['context_menus' => 'yes']]);
    }

    public function testTheTimerOfTheMenuGoesThroughTimeTracksTimerService(): void
    {
        $container = $this->load([]);
        $timers    = $container->getDefinition(TimeTrackTaskTimerStarter::class)->getArgument('$timers');

        self::assertInstanceOf(Reference::class, $timers);
        self::assertSame(TaskBoardExtension::TIME_TRACK_TIMER_SERVICE, (string) $timers);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $timers->getInvalidBehavior());
        self::assertSame(TimeTrackTaskTimerStarter::class, (string) $container->getAlias(TaskTimerStarterInterface::class));
    }

    public function testTheApiFormatterIsRegisteredOnlyWhenTheInterfaceExists(): void
    {
        $api  = ['api' => ['enabled' => true, 'hooks' => ['authenticator' => 'app.auth']]];
        $with = $this->load($api);
        self::assertTrue($with->hasDefinition(TaskApiConflictResponseFormatter::class));
        self::assertTrue($with->getDefinition(TaskApiConflictResponseFormatter::class)->hasTag(ConflictResponseFormatterInterface::TAG));
        $policy = $with->getDefinition(TaskApiRequestListener::class)->getArgument('$conflictPolicy');
        self::assertInstanceOf(Reference::class, $policy);
        self::assertSame('nowo_transaction_doctrine.conflict_response_policy', (string) $policy);
        self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $policy->getInvalidBehavior());

        $without = $this->load($api, new TaskBoardExtension(conflictFormatterInterface: 'Nowo\\TransactionDoctrine\\Missing\\FormatterInterface'));
        self::assertFalse($without->hasDefinition(TaskApiConflictResponseFormatter::class));
        // Without the API there is nothing to shape.
        self::assertFalse($this->load([])->hasDefinition(TaskApiConflictResponseFormatter::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, TaskBoardExtension $extension = new TaskBoardExtension()): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', __DIR__);
        $container->setParameter('kernel.bundles', ['SecurityBundle' => stdClass::class]);
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $extension->load([['user_class' => stdClass::class] + $config], $container);
        self::assertTrue(class_exists(YamlFileLoader::class));

        return $container;
    }
}
