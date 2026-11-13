<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\Legacy;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;

use function in_array;

/**
 * LITERAL copies of the v2.14.1 code that 2.15.0 replaced by {@see Ports} calls, for the
 * equivalence tests. Do not change them: they are the reference behaviour.
 */
final class Legacy2141PortDecisions
{
    /**
     * v2.14.1 src/Import/ImportAssigneeResolver.php:109-111 (and src/Sync/Hook/DefaultSyncPersonResolver.php:73-75).
     */
    public static function importUser(TaskImportUserResolverInterface $userResolver, string $email, TaskBoard $board): ?object
    {
        return $userResolver instanceof BoardAwareTaskImportUserResolverInterface
            ? $userResolver->resolveByEmailForBoard($email, $board)
            : $userResolver->resolveByEmail($email);
    }

    /**
     * v2.14.1 src/Sync/People/TaskSyncPeopleDirectoryService.php:234 (and src/Sync/View/SyncPeopleViewProvider.php:214).
     */
    public static function isAttributionOnly(?object $resolver, TaskBoard $board, object $user): bool
    {
        return $resolver instanceof AttributionOnlyResolverInterface && $resolver->isAttributionOnly($board, $user);
    }

    /**
     * v2.14.1 src/Api/TaskApiWhere.php:56-59 (`reachesBoard()`), on the where's parts.
     *
     * @param list<string> $boardIds
     * @param callable(?string): bool $reachesTenant
     */
    public static function reachesBoard(bool $everything, array $boardIds, callable $reachesTenant, TaskBoard $board): bool
    {
        return $reachesTenant($board->getTenantKey()) || (!$everything && in_array($board->getId(), $boardIds, true));
    }
}
