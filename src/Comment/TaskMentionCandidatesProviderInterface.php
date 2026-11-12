<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see TaskMentionCandidatesResolverInterface}: who may
 * be @mentioned in a task's comments, given a {@see TaskSnapshot}.
 *
 * Set `comments.mention_resolver` to a service implementing it. A service that also implements the deprecated interface
 * is asked through that one until 3.0.
 */
interface TaskMentionCandidatesProviderInterface
{
    /**
     * @return list<MentionCandidate>
     */
    public function candidates(TaskSnapshot $task): array;
}
