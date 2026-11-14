<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Provenance\WriteChannel;
use Nowo\TaskBoardBundle\Provenance\WriteProvenance;
use Nowo\TaskBoardBundle\Provenance\WriteProvenanceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function str_starts_with;

/**
 * Since 2.19.0 (Beacon spec 234): a host's write provenance as Beacon builds it — the channel from the path it serves
 * (`/api/` is the Task API, `/mcp/` its MCP server, anything else its web UI), never from a header or field the client
 * sends; the actor label from what the host knows (here {@see self::$label}, the token's name). Outside a request
 * {@see self::$console} (null by default). Stateless apart from the scripted values the tests set.
 */
final class PathWriteProvenance implements WriteProvenanceProviderInterface
{
    public static ?string $label = null;

    public static ?WriteProvenance $console = null;

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function current(): ?WriteProvenance
    {
        $request = $this->requests->getMainRequest();
        if (!$request instanceof Request) {
            return self::$console;
        }
        $path = $request->getPathInfo();

        return match (true) {
            str_starts_with($path, '/api/') => new WriteProvenance(WriteChannel::Api, self::$label),
            str_starts_with($path, '/mcp/') => new WriteProvenance(WriteChannel::Mcp, self::$label),
            default                         => new WriteProvenance(WriteChannel::Web),
        };
    }

    public static function reset(): void
    {
        self::$label   = null;
        self::$console = null;
    }
}
