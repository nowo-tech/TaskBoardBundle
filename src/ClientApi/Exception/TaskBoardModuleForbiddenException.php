<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Exception;

use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use RuntimeException;

/**
 * Since 3.5.0 (client API v1, Beacon spec 262 block B): the explicit host signal "this token is valid, but this person
 * lost the Tasks permission". The client API answers `403 module_forbidden` (`module: tasks`, no `WWW-Authenticate`) so a
 * client hides the module and keeps its session. Nothing else produces that 403: a disabled or anonymised account (the
 * resolver returns null, or `TaskBoardAccessCheckerInterface::canAccess()` is false) still gets `401 invalid_token`.
 *
 * Throw it from `TaskClientPrincipalResolverInterface::resolve()` (recommended: Beacon maps the `module_forbidden`
 * refusal of TimeTrack's `ClientFamilyTokenAuthenticator` for the `tasks` family to it, instead of returning null) with
 * the principal of the valid token; or from `TaskBoardAccessCheckerInterface::canAccess()` when it is called for the
 * client API (it is also called by the web UI, so prefer the resolver). The principal gives the per-token budget and the
 * audit event their token id; the request carries no task data.
 */
final class TaskBoardModuleForbiddenException extends RuntimeException
{
    public function __construct(public readonly TaskClientPrincipal $principal)
    {
        parent::__construct('tasks_module_forbidden');
    }
}
