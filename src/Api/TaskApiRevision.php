<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use Nowo\TaskBoardBundle\Entity\Task;
use Symfony\Component\HttpFoundation\JsonResponse;

use function preg_match;
use function trim;

/**
 * Since 2.16.0 (spec 234, FR-018): the task revision on HTTP: `ETag: "n"` on a task's answer, `If-Match: "n"` (or `n`,
 * or a weak `W/"n"`) on its update. Stateless.
 */
final class TaskApiRevision
{
    public static function etag(JsonResponse $response, Task $task): JsonResponse
    {
        $response->headers->set('ETag', '"' . $task->getRevision() . '"');

        return $response;
    }

    /**
     * The revision an `If-Match` header names; null without one or for `*` (any revision).
     *
     * @throws TaskApiRefusal 400 `INPUT_002` for anything else
     */
    public static function ifMatch(?string $header): ?int
    {
        $header = trim((string) $header);
        if ($header === '' || $header === '*') {
            return null;
        }
        if (preg_match('/^(?:W\/)?"?([1-9][0-9]{0,9})"?$/D', $header, $match) !== 1 || (int) $match[1] > 2147483647) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid If-Match: a task revision');
        }

        return (int) $match[1];
    }
}
