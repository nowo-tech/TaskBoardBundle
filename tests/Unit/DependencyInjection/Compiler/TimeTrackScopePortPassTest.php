<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimePeriodLock;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TimeTrackScopePortPass;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Since 3.0.0 (audit ZAS-3): with TimeTrack 2.0 the bridge's scope port is TimeTrack's TimesheetScopePolicyInterface
 * id. When TimeTrack has a scope port but does not serve that id, the build fails instead of resolving the scope to null.
 */
final class TimeTrackScopePortPassTest extends TestCase
{
    public function testAMissingPolicyIdWithATimeTrackScopePortFailsTheBuild(): void
    {
        $container = $this->container(TimeTrackScopePortPass::POLICY);
        $container->register(TimeTrackScopePortPass::TIME_TRACK_SLOT, stdClass::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('TimeTrack has a timesheet scope port but does not serve it as "' . TimeTrackScopePortPass::POLICY . '"');

        (new TimeTrackScopePortPass())->process($container);
    }

    public function testThePolicyIdServedByTimeTrackPasses(): void
    {
        $container = $this->container(TimeTrackScopePortPass::POLICY);
        $container->register(TimeTrackScopePortPass::TIME_TRACK_SLOT, stdClass::class);
        $container->setAlias(TimeTrackScopePortPass::POLICY, TimeTrackScopePortPass::TIME_TRACK_SLOT);

        (new TimeTrackScopePortPass())->process($container);
        $this->addToAssertionCount(1);
    }

    /** No TimeTrack scope port at all (timesheets, working time and calendar off): nothing is asked, nothing fails. */
    public function testWithoutATimeTrackScopePortNothingFails(): void
    {
        (new TimeTrackScopePortPass())->process($this->container(TimeTrackScopePortPass::POLICY));
        $this->addToAssertionCount(1);
    }

    /** TimeTrack 1.x: the bridge asks the 1.x id, which this pass does not judge. */
    public function testTheOneXIdAndAbsentConsumersAreLeftAlone(): void
    {
        $container = $this->container(TimesheetScopeProviderInterface::class);
        $container->register(TimeTrackScopePortPass::TIME_TRACK_SLOT, stdClass::class);
        (new TimeTrackScopePortPass())->process($container);

        $bare = new ContainerBuilder();
        $bare->register(TimeTrackScopePortPass::TIME_TRACK_SLOT, stdClass::class);
        (new TimeTrackScopePortPass())->process($bare);
        $this->addToAssertionCount(1);
    }

    private function container(string $scopeId): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(TimeTrackTaskTimeBillableDefaultProvider::class, TimeTrackTaskTimeBillableDefaultProvider::class)
            ->setArgument('$scopes', new Reference($scopeId, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $container->register(TimeTrackTaskTimePeriodLock::class, TimeTrackTaskTimePeriodLock::class)
            ->setArgument('$scopes', new Reference($scopeId, ContainerInterface::NULL_ON_INVALID_REFERENCE));

        return $container;
    }
}
