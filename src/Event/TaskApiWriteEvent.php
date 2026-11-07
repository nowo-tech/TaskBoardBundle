<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 2.2.0 (spec 213): one successful write through the API, after it was saved ({@see TaskBoardEvents::API_WRITE}), for
 * the host's audit (FR-005). It holds ids and field names, never values, free text or a secret.
 */
final class TaskApiWriteEvent extends Event
{
    /**
     * @param string $operation e.g. `task.update`, `board.delete`
     * @param array<string, string> $targetIds other ids touched
     * @param list<string> $changedFields field names only
     */
    public function __construct(
        public readonly TaskApiPrincipal $principal,
        public readonly string $operation,
        public readonly ?string $tenantKey,
        public readonly ?string $boardId,
        public readonly ?string $taskId,
        public readonly array $targetIds = [],
        public readonly array $changedFields = [],
    ) {
    }
}
