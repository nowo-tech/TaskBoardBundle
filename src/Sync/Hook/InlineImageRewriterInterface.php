<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 1.23: rewrites the images of synced Markdown (descriptions, comments) to the stored copies.
 */
interface InlineImageRewriterInterface
{
    /**
     * @param array<string, StoredAttachment> $storedByRemoteUrl
     */
    public function rewrite(Task $task, string $markdown, array $storedByRemoteUrl): string;
}
