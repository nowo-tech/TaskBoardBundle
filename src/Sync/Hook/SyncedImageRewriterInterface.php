<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `InlineImageRewriterInterface` (the 2.14 interface, removed in 3.0): rewrites the images
 * of synced Markdown to the stored copies, given the task as a {@see TaskSnapshot}.
 *
 * Set `sync.inline_image_rewriter` to a service implementing it.
 */
interface SyncedImageRewriterInterface
{
    /**
     * @param array<string, StoredAttachment> $storedByRemoteUrl
     */
    public function rewrite(TaskSnapshot $task, string $markdown, array $storedByRemoteUrl): string;
}
