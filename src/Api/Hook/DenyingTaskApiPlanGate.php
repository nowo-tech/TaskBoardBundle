<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

/**
 * Since 2.2.0 (spec 213): the default gate: no plan holds the module until the host sets `api.hooks.plan_gate`.
 */
final readonly class DenyingTaskApiPlanGate implements TaskApiPlanGateInterface
{
    public function allowsTasks(object $user, ?string $tenantKey): bool
    {
        return false;
    }
}
