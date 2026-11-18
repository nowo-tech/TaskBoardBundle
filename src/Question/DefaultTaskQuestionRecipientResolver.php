<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use Nowo\TaskBoardBundle\Comment\TaskMentionCandidatesResolverInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Repository\TaskRepositoryInterface;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.23.0 (Beacon spec 242): the default recipients — the task's @mention candidates (`comments.mention_resolver`:
 * the people who may read the task), as `user:<id>`, without the asker. A signed-in user answers for `user:<their id>`.
 * Stateless.
 */
final readonly class DefaultTaskQuestionRecipientResolver implements TaskQuestionRecipientResolverInterface
{
    public const PREFIX = 'user:';

    public function __construct(
        private TaskRepositoryInterface $tasks,
        private TaskMentionCandidatesResolverInterface $mentionCandidates,
    ) {
    }

    public function candidates(TaskSnapshot $task, TaskBoardSnapshot $board, object $asker): array
    {
        $entity = $this->tasks->findById($task->id);
        if (!$entity instanceof Task || $entity->getId() !== $task->id) {
            return [];
        }
        $askerId    = ActorId::of($asker);
        $candidates = [];
        foreach ($this->mentionCandidates->candidates($entity) as $candidate) {
            if ($candidate->id !== $askerId) {
                $candidates[] = new TaskQuestionRecipientCandidate(self::PREFIX . $candidate->id, $candidate->label);
            }
        }

        return $candidates;
    }

    public function subjectIdsOf(object $user, ?string $tenantKey): array
    {
        $id = ActorId::of($user);

        return $id === null ? [] : [self::PREFIX . $id];
    }
}
