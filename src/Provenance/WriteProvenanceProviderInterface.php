<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

/**
 * Since 2.18.0 (Beacon spec 234): the provenance of the write running now. The bundle asks it at every task write that
 * leaves a history row (create, update, move, members, ...) and at every comment it creates or edits, and stores the
 * answer on that row: `channel` and `actor_label` (nullable columns). Configure it with `write_provenance_provider`.
 *
 * Framework-free: no request, no entity. The host decides the channel from **the path it serves** (its web UI, its app,
 * the Task API, its MCP endpoint), never from a header or field the client sends, and the label from what it already
 * knows (the API token's name, never the token). Null: nothing to say (a console command, a worker), the columns stay
 * NULL. The default ({@see NoWriteProvenance}) always answers null.
 *
 * Called inside the write (before the flush), possibly several times per request: keep it cheap and free of queries,
 * and keep no state across requests (FrankenPHP worker mode with the kernel kept alive).
 */
interface WriteProvenanceProviderInterface
{
    public function current(): ?WriteProvenance;
}
