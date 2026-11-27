<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Model;

/**
 * Since 3.4.0 (client API v1): the request as the host's ports see it — the client IP, the context header (at most 128
 * bytes, unchecked) and the path. Never the Authorization header, a cookie or the body.
 */
final readonly class TaskClientRequest
{
    public function __construct(
        public ?string $clientIp,
        public ?string $contextHeader,
        public string $path,
        public string $method,
    ) {
    }
}
