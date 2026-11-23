<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Hook\StoredAttachment;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedImageRewriterInterface;

/**
 * Since 1.23: rewrites the images of synced Markdown (descriptions, comments) to the stored copies.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see SyncedImageRewriterInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface InlineImageRewriterInterface
{
    /**
     * @param array<string, StoredAttachment> $storedByRemoteUrl
     */
    public function rewrite(Task $task, string $markdown, array $storedByRemoteUrl): string;
}
