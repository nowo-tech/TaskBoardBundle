<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Comment;

use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Support\UserLabel;

use function is_scalar;
use function method_exists;
use function strcasecmp;
use function usort;

/**
 * Since 1.16: the default mention candidates — the board's assignable users when an assignee provider is configured,
 * else the board's creator and the members of the board's tasks. Hosts with their own read rules replace it.
 */
final readonly class DefaultTaskMentionCandidatesResolver implements TaskMentionCandidatesResolverInterface
{
    public function __construct(
        private ?TaskAssigneeProviderInterface $assigneeProvider = null,
    ) {
    }

    public function candidates(Task $task): array
    {
        $board = $task->getBoard();
        $users = $this->assigneeProvider?->assignableUsers($board);
        if ($users === null) {
            $users = [$board->getCreator()];
            foreach ($board->getTasks() as $boardTask) {
                foreach ($boardTask->getMembers() as $member) {
                    $users[] = $member->getUser();
                }
            }
        }

        $candidates = [];
        foreach ($users as $user) {
            $id = method_exists($user, 'getId') ? $user->getId() : null;
            if (is_scalar($id) && !isset($candidates[(string) $id])) {
                $candidates[(string) $id] = new MentionCandidate((string) $id, UserLabel::of($user), $user);
            }
        }

        $list = array_values($candidates);
        usort($list, static fn (MentionCandidate $a, MentionCandidate $b): int => strcasecmp($a->label, $b->label));

        return $list;
    }
}
