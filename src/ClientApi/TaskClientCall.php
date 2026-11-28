<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use LogicException;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Model\TaskClientPrincipal;
use Nowo\TaskBoardBundle\ClientApi\Security\TaskClientAuthenticator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Since 3.4.0 (client API v1): one authenticated client API request — the host's principal, the context it runs in and
 * the Task API principal the existing guard and operations decide with (level from the scopes, `where` from the context,
 * channel `app`). Kept in the request attributes only (worker mode).
 */
final readonly class TaskClientCall
{
    /** Request attribute of the call. */
    public const ATTRIBUTE = '_nowo_task_board_client_call';

    public function __construct(
        public TaskClientPrincipal $client,
        public TaskApiPrincipal $principal,
        public string $contextKey,
    ) {
    }

    public static function of(Request $request): self
    {
        $call = $request->attributes->get(self::ATTRIBUTE);

        return $call instanceof self ? $call : throw new LogicException('Not an authenticated task board client API request.');
    }

    /**
     * Since 3.7.0: the principal the authenticator resolved (`POST logout` holds no call: it runs no scope or context
     * check).
     */
    public static function principalOf(Request $request): TaskClientPrincipal
    {
        $principal = $request->attributes->get(TaskClientAuthenticator::ATTRIBUTE);

        return $principal instanceof TaskClientPrincipal ? $principal : throw new LogicException('Not an authenticated task board client API request.');
    }
}
