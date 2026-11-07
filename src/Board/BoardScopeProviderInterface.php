<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Board;

use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

/**
 * Since 1.30.0 (spec 211, `board.scope_provider`): where a user may create a board. The bundle draws the steps; the host
 * supplies the scopes and their fields, and decides.
 */
interface BoardScopeProviderInterface
{
    /**
     * What this user may create in, now. Empty: no scope step and today's check on the URL's tenant. One scope: step 1 is
     * skipped.
     *
     * @return list<BoardScope>
     */
    public function scopes(object $user, ?TaskBoardTenant $current): array;

    /**
     * On submit, with the chosen scope's field values only. Must check permissions again: these values come from the
     * browser. The bundle then asks `canCreateBoard()` for the tenant returned. Null: refused.
     *
     * @param array<string, mixed> $values
     */
    public function resolve(object $user, string $scopeId, array $values): ?TaskBoardTenant;
}
