<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Provenance;

use Symfony\Contracts\Service\ResetInterface;

use function array_pop;
use function end;

/**
 * Since 3.7.0 (Beacon spec 235, FR-004): the channel the entry point running now declared for its writes. An entry point
 * states it explicitly around its own call — the bundle's controllers through {@see Attribute\AsWriteChannel} (web
 * pages `web`, Task API `api`, client API `app`), its console commands with `cli` — and every task history row, task
 * and comment written inside {@see self::run()} stores it as its `channel` when the host's
 * {@see WriteProvenanceProviderInterface} says nothing ({@see ScopedWriteProvenance}). A host calls it the same way
 * around its own entry points (an in-process MCP server: `run(WriteChannel::Mcp, …)`).
 *
 * Call-scoped, never global: the channel is pushed when the call starts and popped in `finally`, so nothing outlives the
 * call; calls nest and the innermost wins. Cleared by {@see self::reset()} between the requests, messages and commands of
 * a worker (FrankenPHP worker mode, kernel reset false). Outside any call the channel is null.
 */
final class WriteChannelScope implements ResetInterface
{
    /** @var list<WriteChannel> */
    private array $stack = [];

    /**
     * Runs `$write` with `$channel` and returns what it returns.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function run(WriteChannel $channel, callable $write): mixed
    {
        // @igor-ignore - Scoped to the call: popped in finally, and cleared by reset() between the requests of a worker.
        $this->stack[] = $channel;
        try {
            return $write();
        } finally {
            // @igor-ignore - Scoped to the call (see above).
            array_pop($this->stack);
        }
    }

    /** The channel of the writes running now; null outside {@see self::run()}. */
    public function current(): ?WriteChannel
    {
        $stack = $this->stack;

        return $stack === [] ? null : end($stack);
    }

    public function reset(): void
    {
        // @igor-ignore - Cleared between the requests of a worker.
        $this->stack = [];
    }
}
