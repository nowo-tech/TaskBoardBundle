<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Number;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.1.0 (spec 214): the sequence a board's tasks draw from (`task_numbers.scope`). With `tenant`, every board of a
 * tenant shares one and a board without a tenant key uses its own id. With `board`, each board has its own.
 */
final readonly class TaskNumberScope
{
    public const TENANT = 'tenant';
    public const BOARD  = 'board';

    public function __construct(
        private string $scope = self::TENANT,
    ) {
    }

    public function keyOf(TaskBoard $board): string
    {
        $tenantKey = $board->getTenantKey();

        return $this->scope === self::TENANT && $tenantKey !== null && $tenantKey !== '' ? $tenantKey : $board->getId();
    }

    /** Whether a number alone, with the tenant of the request, names one task. */
    public function isPerTenant(): bool
    {
        return $this->scope === self::TENANT;
    }
}
