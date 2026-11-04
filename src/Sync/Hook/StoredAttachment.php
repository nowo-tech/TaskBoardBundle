<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

/** Since 1.23: a file the host stored (its URL is what the task links to). */
final readonly class StoredAttachment
{
    public function __construct(
        public string $ref,
        public string $url,
        public string $name,
        public int $size,
        public string $mime,
        public ?string $sha256 = null,
    ) {
    }
}
