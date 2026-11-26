<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Contract;

use Nowo\TaskBoardBundle\Api\TaskApiWhere;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientContext;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;

/**
 * Since 3.4.0 (client API v1, R4): the tenant of a client API request, named by the client in the context header
 * (`client_api.context_header`, `X-Beacon-Context` by default) or bound to the token, and checked on every request.
 * Required: without it the client API does not build. The bundle never stores the context in a service and never changes
 * the person's web context.
 *
 * Every method is called per request: answer from current data (a membership revoked a second ago is refused now).
 */
interface TaskClientContextPolicyInterface
{
    /**
     * The contexts the person may use now (`GET me`, the context switcher), in display order.
     *
     * @return list<TaskClientContext>
     */
    public function contextsFor(TaskClientPrincipal $principal): array;

    /**
     * Whether the person may use the context now. The key was matched against {@see TaskClientPrincipal::KEY_PATTERN};
     * answer for this exact key only. False answers 403 `context_forbidden` (the same answer as an unknown context).
     */
    public function allows(TaskClientPrincipal $principal, string $contextKey): bool;

    /**
     * The tenants (and, optionally, boards) of the context, as the existing guard reads them: typically
     * `TaskApiWhere::limitedTo($tenantKeys, [])` (Beacon: `personal` → the personal tenant key, `org:<uuid>` → the
     * organisation's tenant keys and its projects' keys, minus organisations that forbid the task API). Never
     * `TaskApiWhere::everything()` for a context: one context never reaches another's boards.
     */
    public function whereFor(TaskClientPrincipal $principal, string $contextKey): TaskApiWhere;
}
