<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\TimeTrack;

use Nowo\TimeTrackBundle\Port\TimeTrackUserRefFactory;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopePolicyInterface;
use Nowo\TimeTrackBundle\Timesheet\TimesheetScopeProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function interface_exists;

/**
 * Since 2.28.0: the TimeTrack tenant scope of a task for a user, through whichever scope port the installed TimeTrack
 * serves: its 1.x `TimesheetScopeProviderInterface` service (TimeTrack wraps a host's framework-free policy in it), or
 * the framework-free `TimesheetScopePolicyInterface` (the only one once the 1.x interface is gone). null: no scope port,
 * a user that is not a security user, or a task without a scope. Stateless.
 *
 * @internal
 */
final class TimeTrackScopeKeys
{
    /**
     * @param TimesheetScopePolicyInterface|TimesheetScopeProviderInterface|null $scopes TimeTrack's scope port
     */
    public static function forTask(?object $scopes, object $user, string $taskId, ?string $boardId): ?string
    {
        if (!$user instanceof UserInterface) {
            return null;
        }

        return match (true) {
            $scopes instanceof TimesheetScopeProviderInterface => $scopes->scopeKeyForTask($user, $taskId, $boardId),
            $scopes instanceof TimesheetScopePolicyInterface   => $scopes->scopeKeyForUserTask(TimeTrackUserRefFactory::build($user), $taskId, $boardId),
            default                                            => null,
        };
    }

    /**
     * The service id of TimeTrack's scope port: the 1.x interface while it exists, else the framework-free one.
     *
     * @param string $legacy the 1.x interface (a parameter for the tests)
     */
    public static function serviceId(string $legacy = TimesheetScopeProviderInterface::class): string
    {
        return interface_exists($legacy) ? $legacy : TimesheetScopePolicyInterface::class;
    }
}
