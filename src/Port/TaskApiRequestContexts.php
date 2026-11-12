<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Api\TaskApiCredential;
use Nowo\TaskBoardBundle\Api\Value\TaskApiRequestContext;
use Nowo\TaskBoardBundle\Api\Value\TaskApiUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function is_string;
use function strtolower;

/**
 * Since 2.15.0: copies the HTTP request and uploaded files into the framework-free values of the Task API ports. Cookies
 * are never copied. Stateless.
 *
 * @internal
 */
final class TaskApiRequestContexts
{
    public static function fromRequest(Request $request): TaskApiRequestContext
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            if ($name === 'cookie' || !isset($values[0]) || !is_string($values[0])) {
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
