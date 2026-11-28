<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Contract;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientRequest;
use Nowo\TaskBoardBundle\Event\TaskClientLoggedOutEvent;

/**
 * Since 3.7.0 (client API v1, Beacon Desktop BDA13-L2, `POST <prefix>/v1/logout`): revokes the one Bearer token a client
 * signs out with — the same contract as ChatKit 2.4.1's `ChatClientTokenRevokerInterface`. Optional
 * (`client_api.token_revoker`): TaskBoard issues and stores no token, so only the host can revoke one (Beacon:
 * TimeTrack's `ClientFamilyTokenAuthenticator::revoke('tasks', …)`). Without it, logout only tells the client to forget
 * its token and the token stays valid until it expires or is revoked in the host's UI (`me.features.serverLogout` is
 * false).
 *
 * Called once per logout, after {@see TaskClientPrincipalResolverInterface::resolve()} accepted the same token in the
 * same request, with the token exactly as sent and the principal resolve() answered. The host:
 *
 * - revokes this token only: never the person's other tokens, devices or sessions, and never a token of another client
 *   family (a `time` or `chat` token is not revoked by the task board API, even if resolve() was lenient);
 * - is idempotent: a token that is already revoked, expired or unknown is a no-op, never an error (two devices, a retry
 *   after a timeout, or two concurrent calls may revoke the same token);
 * - commits the revocation in its own write unit before it returns (REQ-RUNTIME-003), so the next request with the
 *   token is refused by resolve() (`401 invalid_token`); {@see TaskClientLoggedOutEvent} is dispatched after it returns;
 * - lets a transient failure (database down, lock conflict) propagate: the client gets an error and retries; never
 *   swallow it, the token would stay valid while the client believes it is signed out;
 * - keeps nothing of the call (FrankenPHP worker mode, kernel reset false).
 *
 * A token the resolver refuses is never revoked by logout (the answer is the same 204). So the resolver should refuse
 * only for reasons of the token itself (unknown, expired, revoked, another client family), or the host revokes a
 * person's client tokens when it disables the account; a token whose person lost the Tasks permission
 * (`TaskBoardModuleForbiddenException`) is revoked like any other.
 */
interface TaskClientTokenRevokerInterface
{
    /**
     * Revokes one Bearer token.
     *
     * @param string $bearer the token as sent after `Bearer ` (trimmed, never empty, at most 4096 bytes)
     * @param TaskClientPrincipal $principal what resolve() answered for it in this request
     * @param TaskClientRequest $request the request (no header, cookie or body; the context header is not passed)
     */
    public function revoke(string $bearer, TaskClientPrincipal $principal, TaskClientRequest $request): void;
}
