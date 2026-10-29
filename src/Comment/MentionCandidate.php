<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\Event\TaskMentionEvent;

/**
 * Since 1.16: a person who may be @mentioned on a task — the id stored with the comment, the name typed after "@"
 * and the user object handed to {@see TaskMentionEvent}.
 */
final readonly class MentionCandidate
{
    public function __construct(
        public string $id,
        public string $label,
        public object $user,
    ) {
    }
}
