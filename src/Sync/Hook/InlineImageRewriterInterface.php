<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 1.23: rewrites the images of synced Markdown (descriptions, comments) to the stored copies.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see SyncedImageRewriterInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
 */
interface InlineImageRewriterInterface
{
    /**
     * @param array<string, StoredAttachment> $storedByRemoteUrl
     */
    public function rewrite(Task $task, string $markdown, array $storedByRemoteUrl): string;
}
