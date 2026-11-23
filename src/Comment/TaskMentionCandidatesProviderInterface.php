<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskMentionCandidatesResolverInterface` (the 2.14 interface, removed in 3.0): who may
 * be @mentioned in a task's comments, given a {@see TaskSnapshot}.
 *
 * Set `comments.mention_resolver` to a service implementing it.
 */
interface TaskMentionCandidatesProviderInterface
{
    /**
     * @return list<MentionCandidate>
     */
    public function candidates(TaskSnapshot $task): array;
}
