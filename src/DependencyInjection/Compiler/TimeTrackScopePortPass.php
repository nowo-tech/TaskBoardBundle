<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimeBillableDefaultProvider;
use Nowo\TaskBoardBundle\Bridge\TimeTrack\TimeTrackTaskTimePeriodLock;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * Since 3.0.0 (audit ZAS-3): with TimeTrack 2.0 (its 1.x `TimesheetScopeProviderInterface` id is gone) TaskBoard's
 * billable default and period lock of manual entries ask TimeTrack's framework-free `TimesheetScopePolicyInterface` by
 * its service id. When TimeTrack has a timesheet scope port (its internal slot exists) but does not serve that id, the
 * build fails instead of resolving the scope to nothing (the period lock would then check every scope of the user, and
 * the billable default would be asked for scope ''). Without a TimeTrack scope port at all (timesheets, working time
 * and calendar off) nothing is asked and nothing changes. With TimeTrack 1.x (the 1.x id) the pass does nothing.
 *
 * Runs before optimization at a low priority (-100): after every bundle's ports pass (TimeTrack 2.0 adds the id there),
 * before Symfony resolves invalid references to null.
 *
 * @internal
 */
final class TimeTrackScopePortPass implements CompilerPassInterface
{
    /** TimeTrack 2.0's framework-free scope port, the id TaskBoard injects. */
    public const POLICY = TimesheetScopePolicyInterface::class;

    /** TimeTrack 2.0's internal scope slot: present when TimeTrack has a scope port. */
    public const TIME_TRACK_SLOT = 'Nowo\TimeTrackBundle\Port\Internal\TimesheetScopeProviderInterface';

    /** The bridge services that ask the scope port (argument `$scopes`). */
    private const CONSUMERS = [TimeTrackTaskTimeBillableDefaultProvider::class, TimeTrackTaskTimePeriodLock::class];

    /**
     * @throws LogicException when TimeTrack has a scope port but does not serve it as TimesheetScopePolicyInterface
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(self::POLICY) || !$container->has(self::TIME_TRACK_SLOT)) {
            return;
        }
        foreach (self::CONSUMERS as $id) {
            if (!$container->hasDefinition($id)) {
                continue;
            }
            $scopes = $container->getDefinition($id)->getArguments()['$scopes'] ?? null;
            if ($scopes instanceof Reference && (string) $scopes === self::POLICY) {
                throw new LogicException(sprintf('nowo_task_board: TimeTrack has a timesheet scope port but does not serve it as "%s", which TaskBoard\'s TimeTrack bridge (%s) asks for the scope of a task. Use a TimeTrack 2.x release that registers that id (or alias it to your scope policy service); see UPGRADING.md "To 3.0.0", "TimeTrack bridge".', self::POLICY, $id));
            }
        }
    }
}
