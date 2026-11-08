<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

/**
 * Since 2.2.0 (spec 213): whether a plan holds the tasks module (FR-009). Default: {@see DenyingTaskApiPlanGate}, 403 `PLAN_001`.
 */
interface TaskApiPlanGateInterface
{
    /**
     * The module on the account owning `$tenantKey`; null: on any account of the user (`GET /v2/user`, `/v2/team`).
     */
    public function allowsTasks(object $user, ?string $tenantKey): bool;
}
