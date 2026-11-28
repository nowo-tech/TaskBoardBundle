<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

/**
 * Since 2.18.0 (Beacon spec 234, write provenance): where a write came from. An allow-list: the host picks one from the
 * request path it serves (its web UI, its own app, the Task API, its MCP server), never from a value the client sends.
 *
 * Since 3.7.0 (Beacon spec 235, FR-004) the bundle also states it at its own entry points when the host says nothing
 * ({@see WriteChannelScope}): its web pages `web`, the Task API `api`, the client API of a first-party app
 * (`/task-board/v1`, Beacon Desktop) `app`, its console commands `cli`. An in-process MCP server is `mcp`. Every value
 * fits the 8-character `channel` columns.
 */
enum WriteChannel: string
{
    case Web = 'web';
    case App = 'app';
    case Api = 'api';
    case Mcp = 'mcp';

    /** Since 3.7.0: a console command (`nowo:task-board:import`, `nowo:task-board:sync`, a host's own). */
    case Cli = 'cli';
}
