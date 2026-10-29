<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 1.16: who may be @mentioned in a task's comments — the people who can read the task. Only the host knows who
 * can read a board (project members, people a board is shared with…): set `nowo_task_board.comments.mention_resolver`
 * to your service. The default ({@see DefaultTaskMentionCandidatesResolver}) offers the assignable users of the board
 * (the assignee provider), else the board's creator and the members of its tasks.
 */
interface TaskMentionCandidatesResolverInterface
{
    /**
     * @return list<MentionCandidate>
     */
    public function candidates(Task $task): array;
}
