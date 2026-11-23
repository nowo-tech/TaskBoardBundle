<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Comment\MentionCandidate;
use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesProviderInterface;
use Nowo\TaskBoardBundle\Entity\Task;

/**
 * Since 1.16: who may be @mentioned in a task's comments — the people who can read the task. Only the host knows who
 * can read a board (project members, people a board is shared with…): set `nowo_task_board.comments.mention_resolver`
 * to your service. The default ({@see DefaultTaskMentionCandidatesResolver}) offers the assignable users of the board
 * (the assignee provider), else the board's creator and the members of its tasks.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskMentionCandidatesProviderInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskMentionCandidatesResolverInterface
{
    /**
     * @return list<MentionCandidate>
     */
    public function candidates(Task $task): array;
}
