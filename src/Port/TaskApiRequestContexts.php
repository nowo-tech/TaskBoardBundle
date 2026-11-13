<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function is_string;
use function str_starts_with;
use function strtolower;

/**
 * Since 2.15.0: copies the HTTP request and uploaded files into the framework-free values of the Task API ports. Credential
 * headers (Authorization, Proxy-Authorization, PHP-Auth-*, Cookie, X-Api-Key) are never copied: the credential reaches
 * the host only as `TaskApiRequestContext::$credential` (read by TaskApiCredential). Stateless.
 *
 * @internal
 */
final class TaskApiRequestContexts
{
    /** Headers never copied (lower case); `php-auth-*` too. */
    public const CREDENTIAL_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'x-api-key'];

    public static function fromRequest(Request $request): TaskApiRequestContext
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            // Audit OC-I1: credentials never enter the context a host may log; the credential itself is `$credential`.
            if (in_array($name, self::CREDENTIAL_HEADERS, true) || str_starts_with($name, 'php-auth-') || !isset($values[0]) || !is_string($values[0])) {
                continue;
            }
            $headers[$name] = $values[0];
        }
        $route = $request->attributes->get('_route');

        return new TaskApiRequestContext(
            $request->getMethod(),
            $request->getPathInfo(),
            is_string($route) ? $route : null,
            $request->getClientIp(),
            TaskApiCredential::fromRequest($request),
            $headers,
        );
    }

    public static function upload(UploadedFile $file): TaskApiUpload
    {
        // SplFileInfo::getSize() throws when the file is gone; Symfony defaults an empty client type to application/octet-stream.
        return new TaskApiUpload(
            $file->getPathname(),
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            (int) $file->getSize(),
        );
    }
}
