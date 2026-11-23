<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Service\TaskChangeContext;

/**
 * Since 2.10.0 (spec 216, FR-014): `comments.cause_labeler` — how a comment shows why it was written (its `cause`, the
 * {@see TaskChangeContext} it was added in, e.g. "rule:42"), next to its author:
 * "(rule: Review goes to Luis)". Return the text, which is escaped, or null to show nothing. Without a labeler no cause is
 * shown. One host service may implement this and `TaskChangeCauseLabelerInterface` both.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskCommentCauseTextInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskCommentCauseLabelerInterface
{
    public function label(string $cause, TaskComment $comment): ?string;
}
