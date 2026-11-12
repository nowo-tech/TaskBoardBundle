<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Hook\InlineImageRewriterInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncedImageRewriterInterface;

/**
 * Since 2.15.0: asks a host {@see SyncedImageRewriterInterface} where the bundle asks the deprecated
 * {@see InlineImageRewriterInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class SyncedImageRewriterGate implements InlineImageRewriterInterface
{
    public function __construct(private SyncedImageRewriterInterface $rewriter)
    {
    }

    public function rewrite(Task $task, string $markdown, array $storedByRemoteUrl): string
    {
        return $this->rewriter->rewrite(Snapshots::task($task), $markdown, $storedByRemoteUrl);
    }
}
