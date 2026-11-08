<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;

/**
 * Since 2.2.0 (spec 213): who a request is, as the host's authenticator answers it, for this request only. It holds no
 * secret: `$tokenId` is an opaque id.
 */
final readonly class TaskApiPrincipal
{
    /** Since 2.4.0: a request through the HTTP API. */
    public const CHANNEL_API = 'api';

    /** Since 2.4.0 (spec 219): an in-process caller such as the host's MCP server. */
    public const CHANNEL_MCP = 'mcp';

    /**
     * @param string $channel since 2.4.0: {@see self::CHANNEL_API} (default), or {@see self::CHANNEL_MCP} for an in-process
     *                        caller (spec 219); passed on to the audit event ({@see TaskApiWriteEvent::$channel}). It changes
     *                        no access decision.
     */
    public function __construct(
        public object $user,
        public TaskApiLevel $level,
        public TaskApiWhere $where,
        public string $tokenId,
        public string $tokenName,
        public string $channel = self::CHANNEL_API,
    ) {
    }
}
