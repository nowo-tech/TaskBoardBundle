<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use Nowo\TaskBoardBundle\ClientApi\Contract\TaskClientTokenRevokerInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Since 3.7.0 (client API v1, Beacon Desktop BDA13-L2): {@see TaskBoardEvents::CLIENT_API_LOGGED_OUT}, a client signed
 * out through `POST <prefix>/v1/logout` with a token the host's resolver accepted (as ChatKit's
 * `ChatClientLoggedOutEvent`). For the host's audit log: ids only — never the token, the Authorization header, a full IP
 * or task data. Dispatched after {@see TaskClientTokenRevokerInterface::revoke()} returned (after the host's commit). A
 * logout with an unknown, expired or revoked token answers the same 204 and dispatches nothing (there is no principal to
 * name). Two concurrent logouts of one token can both pass the resolver before the host's commit: the event may then be
 * dispatched twice for one token; deduplicate on `tokenId` if needed.
 */
final class TaskClientLoggedOutEvent extends Event
{
    /**
     * @param string $tokenId the token's id from the principal (never the secret)
     * @param string|null $userId the token's person (the bundle's user id), null when the user has none
     * @param bool $revoked true when the host's revoker revoked the token; false when no revoker is configured (the token
     *                      stays valid until it expires; only the client forgot it)
     * @param string|null $ipPrefix the client's network: an IPv4 /24 or IPv6 /64; null when unknown
     */
    public function __construct(
        public readonly string $tokenId,
        public readonly ?string $userId,
        public readonly bool $revoked,
        public readonly ?string $ipPrefix = null,
    ) {
    }
}
