<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

/**
 * Since 2.19.0 (Beacon spec 234, write provenance): where a write came from. An allow-list: the host picks one from the
 * request path it serves (its web UI, its own app, the Task API, its MCP server), never from a value the client sends.
 */
enum WriteChannel: string
{
    case Web = 'web';
    case App = 'app';
    case Api = 'api';
    case Mcp = 'mcp';
}
