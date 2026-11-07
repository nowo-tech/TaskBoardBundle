<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use Symfony\Component\HttpFoundation\Request;

use function is_string;
use function preg_replace;
use function trim;

/**
 * Since 2.2.0 (spec 213): the credential of a request. The `Authorization` header only, with or without `Bearer`; never
 * the query string, where it would reach logs (FR-002).
 */
final class TaskApiCredential
{
    public static function fromRequest(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!is_string($header)) {
            return null;
        }
        $credential = trim((string) preg_replace('/^Bearer(\s+|$)/i', '', trim($header)));

        return $credential === '' ? null : $credential;
    }
}
