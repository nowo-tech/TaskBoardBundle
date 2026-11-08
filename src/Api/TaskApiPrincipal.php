<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Event\TaskApiWriteEvent;

use function preg_match;

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
     *                        no access decision. Must match `[a-z][a-z0-9_]{0,31}` (else InvalidArgumentException).
     *                        Derive it from the call path (the in-process MCP server), never from what the client sends.
     */
    public function __construct(
        public object $user,
        public TaskApiLevel $level,
        public TaskApiWhere $where,
        public string $tokenId,
        public string $tokenName,
        public string $channel = self::CHANNEL_API,
    ) {
        // Audit II-I4: a short lowercase label (`api`, `mcp`, or a host's own), never free text in the audit.
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $channel) !== 1) {
            throw new InvalidArgumentException('A TaskApiPrincipal channel is a lowercase label of at most 32 characters ([a-z][a-z0-9_]*).');
        }
    }
}
