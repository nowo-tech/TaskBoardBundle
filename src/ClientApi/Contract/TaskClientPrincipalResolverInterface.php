<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Contract;

use Nowo\TaskBoardBundle\ClientApi\Exception\TaskBoardModuleForbiddenException;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientRequest;

/**
 * Since 3.4.0 (client API v1, Q-BD-22): who a Bearer token of the client API belongs to. TaskBoard issues and stores no
 * token: the host plugs in its own (Beacon: device-flow tokens of the `tasks` family over TimeTrack's
 * `ClientFamilyTokenAuthenticator`), and the client API is off until it does (`client_api.principal_resolver`).
 *
 * Called once per request by the bundle's authenticator (`nowo_task_board.client_api.authenticator`), before any task
 * data is read, with the token as sent (at most 4096 bytes). Resolve it on every call: never cache a principal across
 * requests (FrankenPHP worker mode, kernel reset false), so a revoked token, a disabled account or a lost membership is
 * refused on the next request. Compare the token in constant time (store a hash). Return null for an unknown, expired or
 * revoked token, and for a token of another client family (a `time` or `chat` token): the API answers 401
 * `invalid_token`. The principal's user must be the host's security user (a `UserInterface`).
 *
 * 3.5 (Beacon spec 262 block B): return null for a disabled or anonymised account too (401: the client signs out). For a
 * valid token whose person lost the Tasks permission, throw {@see TaskBoardModuleForbiddenException} with the principal
 * instead of returning null: the API answers `403 module_forbidden` (`module: tasks`, no `WWW-Authenticate`), so the
 * client hides the module and keeps its session. Nothing else produces that 403, and an inactive account is a 401 even if
 * you throw it. Throw it only here, never from `TaskBoardAccessCheckerInterface::canAccess()`.
 */
interface TaskClientPrincipalResolverInterface
{
    public function resolve(string $bearer, TaskClientRequest $request): ?TaskClientPrincipal;
}
