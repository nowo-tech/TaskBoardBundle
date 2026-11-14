<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Hook;

use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

/**
 * Since 2.16.0 (spec 234): the default: only the comment's author (same `getId()`, compared byte for byte) edits it;
 * a comment whose author was erased is edited by nobody. Stateless.
 */
final readonly class AuthorOnlyTaskApiCommentEditPolicy implements TaskApiCommentEditPolicyInterface
{
    public function mayEdit(TaskApiPrincipal $principal, TaskCommentSnapshot $comment): bool
    {
        $actor = ActorId::of($principal->user);

        return $actor !== null && $comment->authorId !== null && $actor === $comment->authorId;
    }
}
