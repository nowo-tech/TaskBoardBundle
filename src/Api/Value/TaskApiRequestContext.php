<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Api\Hook\TaskApiRequestAuthenticatorInterface;
use Nowo\TaskBoardBundle\Api\TaskApiCredential;

use function strtolower;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 3): the request a Task API call came in, as the framework-free
 * {@see TaskApiRequestAuthenticatorInterface} sees it. Copied from the HTTP request when
 * the authenticator is asked; holds no request, session or cookie.
 *
 * `$credential` is what {@see TaskApiCredential::fromRequest()} reads: the `Authorization`
 * header without `Bearer`, never the query string. Header names are lower case; each header keeps its first value.
 * Credential headers (Authorization, Proxy-Authorization, PHP-Auth-*, Cookie, X-Api-Key) are left out, so the context
 * can be logged.
 */
final readonly class TaskApiRequestContext
{
    /**
     * @param array<string, string> $headers lower-case name => first value
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $route,
        public ?string $clientIp,
        public ?string $credential,
        public array $headers = [],
    ) {
    }

    /** A request header (case-insensitive name), first value; null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
