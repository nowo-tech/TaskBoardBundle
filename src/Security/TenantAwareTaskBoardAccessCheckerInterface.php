<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

/**
 * Since 1.24: a {@see TaskBoardAccessCheckerInterface} whose create check knows the tenant of the request
 * (`tenancy`): the board index and the create action pass the current tenant key, so a host decides "may create a
 * board *here*" (e.g. a project permission) instead of patching the create route. Checkers that only implement
 * {@see TaskBoardAccessCheckerInterface} keep working: they are asked without the tenant.
 */
interface TenantAwareTaskBoardAccessCheckerInterface extends TaskBoardAccessCheckerInterface
{
    /**
     * Whether `$user` may create a board, in the tenant `$tenantKey` when one is given (null without tenancy or
     * outside a tenant URL).
     */
    public function canCreateBoard(object $user, ?string $tenantKey = null): bool;

    /**
     * Since 2.0.0: whether `$user` may list the boards of the tenant `$tenantKey`. With a tenant, a refusal answers 404 on
     * its index and create route. Without a tenant, a refusal shows an empty index, as before.
     */
    public function canListBoards(object $user, ?string $tenantKey = null): bool;
}
