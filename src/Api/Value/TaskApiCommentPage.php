<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Value;

use Nowo\TaskBoardBundle\Entity\TaskComment;

/**
 * Since 2.2.0 (spec 213): a page of comments, newest first, with their authors as the viewer may see them.
 */
final readonly class TaskApiCommentPage
{
    /**
     * @param list<TaskComment> $comments
     * @param array<string, TaskApiUser> $authors by comment id
     */
    public function __construct(
        public array $comments,
        public array $authors,
    ) {
    }
}
