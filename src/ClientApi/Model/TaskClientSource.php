<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Model;

/**
 * Since 3.4.0 (client API v1, §6.2 of the Beacon Desktop tasks design): what a new task was made from, as the client
 * sends it in `POST tasks` (`source: {type, ...}`) — the type (`[a-z][a-z0-9_]{0,31}`) and the other string fields as
 * sent (at most 8, each at most 128 bytes). The host's `TaskClientSourceLinkInterface` decides whether the person may
 * link it and to which host object.
 */
final readonly class TaskClientSource
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public string $type,
        public array $fields,
    ) {
    }
}
