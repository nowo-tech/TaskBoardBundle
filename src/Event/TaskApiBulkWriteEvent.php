<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Symfony\Contracts\EventDispatcher\Event;

use function count;

/**
 * Since 2.16.0 (spec 234, FR-021 / FR-022): one atomic bulk update through the Task API, after its commit
 * ({@see TaskBoardEvents::API_BULK_WRITE}), besides the one {@see TaskApiWriteEvent} per task. `$count` is how many
 * tasks it wrote, so a host can count them against a write budget (e.g. its MCP rate limit). Ids only.
 */
final class TaskApiBulkWriteEvent extends Event
{
    /** The principal's channel (`api`, `mcp` or a host's own). */
    public readonly string $channel;

    /** How many tasks the bulk update wrote. */
    public readonly int $count;

    /**
     * @param list<string> $taskIds in the order of the request
     */
    public function __construct(
        public readonly TaskApiPrincipal $principal,
        public readonly ?string $tenantKey,
        public readonly array $taskIds,
    ) {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->channel = $principal->channel;
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->count = count($taskIds);
    }
}
