<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;

/**
 * Since 2.10.0 (DDD audit G4): the port of {@see TaskCommentManager}, which adds, edits and deletes task comments. A
 * host's application layer depends on it and can double or decorate it; the container aliases it to the bundle's class.
 * The bundle's own controllers keep using the class, so a host decorator wraps the host's calls only.
 *
 * No access check (audit JY-L2): like the class, it trusts its caller. Before calling it the host checks what the bundle's
 * controllers check: `TaskBoardResourceAccessChecker` (`canEditTask` / `canEditBoard` / `canManageBoard` /
 * `canManageColumns` / `canTrackTime`), tenant ownership and plan, comment rights (`comments.readers_can_comment`), the
 * active-task quota (`TaskManager::activeTaskRefusal()` before a create or restore) and CSRF.
 */
interface TaskCommentManagerInterface
{
    /**
     * @param list<string> $mentionIds Candidate ids picked in the comment box
     *
     * @throws InvalidArgumentException An empty or too long body
     */
    public function add(Task $task, object $author, string $body, array $mentionIds = []): TaskComment;

    /**
     * @param list<string> $mentionIds
     *
     * @throws InvalidArgumentException An empty or too long body
     */
    public function edit(TaskComment $comment, object $editor, string $body, array $mentionIds = []): void;

    /**
     * @param object|null $actor since 2.10.0: who deletes it, for {@see TaskBoardEvents::TASK_COMMENT_DELETED} (null: unknown)
     */
    public function delete(TaskComment $comment, ?object $actor = null): void;
}
