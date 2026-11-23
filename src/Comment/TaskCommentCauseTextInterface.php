<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskCommentCauseLabelerInterface` (the 2.14 interface, removed in 3.0): the text a
 * comment shows for its cause, given a {@see TaskCommentSnapshot}. Return the text (escaped) or null to show nothing.
 *
 * Set `comments.cause_labeler` to a service implementing it.
 */
interface TaskCommentCauseTextInterface
{
    public function label(string $cause, TaskCommentSnapshot $comment): ?string;
}
