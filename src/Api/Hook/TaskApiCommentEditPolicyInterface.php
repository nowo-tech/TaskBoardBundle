<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

/**
 * Since 2.16.0 (spec 234): who may edit a comment through `PUT /v2/comment/{id}`, asked only after the guard let the
 * principal comment on the comment's task (its tenant and reach). A refusal answers 404, as for a comment that does not
 * exist. Framework-free; keep no state between calls. Default: {@see AuthorOnlyTaskApiCommentEditPolicy}.
 */
interface TaskApiCommentEditPolicyInterface
{
    public function mayEdit(TaskApiPrincipal $principal, TaskCommentSnapshot $comment): bool;
}
