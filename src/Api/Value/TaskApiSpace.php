<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): a ClickUp space: a host's tenant of boards. `$tenantKey` null: boards without a tenant.
 */
final readonly class TaskApiSpace
{
    public function __construct(
        public string $id,
        public string $name,
        public string $teamId,
        public ?string $tenantKey,
    ) {
    }
}
