<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see TaskCommentCauseLabelerInterface}: the text a
 * comment shows for its cause, given a {@see TaskCommentSnapshot}. Return the text (escaped) or null to show nothing.
 *
 * Set `comments.cause_labeler` to a service implementing it. A service that also implements the deprecated interface is
 * asked through that one until 3.0.
 */
interface TaskCommentCauseTextInterface
{
    public function label(string $cause, TaskCommentSnapshot $comment): ?string;
}
