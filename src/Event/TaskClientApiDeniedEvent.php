<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 3.4.0 (client API v1): {@see TaskBoardEvents::CLIENT_API_DENIED}, every refusal of the client API from
 * authentication on (as ChatKit's `ChatClientApiDeniedEvent`): the reason code, the token id and the user's public id
 * when known, and the client's network (`/24` for IPv4, `/64` for IPv6). Ids and codes only: no token, path, body or
 * name.
 */
final class TaskClientApiDeniedEvent extends Event
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $tokenId,
        public readonly ?string $userId,
        public readonly ?string $ipPrefix,
    ) {
    }
}
