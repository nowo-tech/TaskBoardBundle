<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

/**
 * Since 2.2.0 (spec 213): a file the host stored for a task.
 */
final readonly class TaskApiAttachment
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public int $size,
    ) {
    }
}
