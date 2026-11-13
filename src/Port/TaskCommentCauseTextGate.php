<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskComment;

/**
 * Since 2.15.0: asks a host {@see TaskCommentCauseTextInterface} where the bundle asks the deprecated {@see TaskCommentCauseLabelerInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskCommentCauseTextGate implements TaskCommentCauseLabelerInterface
{
    public function __construct(private TaskCommentCauseTextInterface $text)
    {
    }

    public function label(string $cause, TaskComment $comment): ?string
    {
        return $this->text->label($cause, Snapshots::comment($comment));
    }
}
