<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;

/**
 * Since 2.10.0 (spec 216, FR-014): `comments.cause_labeler` — how a comment shows why it was written (its `cause`, the
 * {@see TaskChangeContext} it was added in, e.g. "rule:42"), next to its author:
 * "(rule: Review goes to Luis)". Return the text, which is escaped, or null to show nothing. Without a labeler no cause is
 * shown. One host service may implement this and `TaskChangeCauseLabelerInterface` both.
 */
interface TaskCommentCauseLabelerInterface
{
    public function label(string $cause, TaskComment $comment): ?string;
}
