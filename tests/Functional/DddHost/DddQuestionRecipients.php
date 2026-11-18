<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientCandidate;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientResolverInterface;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Beacon spec 242 on the DDD host: recipients from the application layer (here the in-memory directory), as opaque ids —
 * a member as `member:<id>` and a client contact as `contact:<id>` (external) — values only.
 */
final readonly class DddQuestionRecipients implements TaskQuestionRecipientResolverInterface
{
    public function candidates(TaskSnapshot $task, TaskBoardSnapshot $board, object $asker): array
    {
        $candidates = [];
        foreach (DddUsers::all() as $id => $user) {
            if (!$asker instanceof DddUser || $asker->getId() !== $id) {
                $candidates[] = new TaskQuestionRecipientCandidate('member:' . $id, UserLabel::of($user));
            }
        }
        $candidates[] = new TaskQuestionRecipientCandidate('contact:42', 'Client contact', true);
        // Dropped: an id that cannot be matched exactly.
        $candidates[] = new TaskQuestionRecipientCandidate(" padded \u{200B}", 'Look-alike');

        return $candidates;
    }

    public function subjectIdsOf(object $user, ?string $tenantKey): array
    {
        return $user instanceof DddUser ? ['member:' . $user->getId(), ''] : [];
    }
}
