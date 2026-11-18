<?php

declare(strict_types=1);

namespace App\TaskBoard;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientCandidate;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientResolverInterface;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * TaskBoardBundle 2.23 (questions on tasks): the demo's recipients — every demo user as `user:<id>`, the signed-in one
 * included (the demo has one visitor, who then answers their own questions), and a client contact that would answer
 * through a signed link (`external`).
 */
final readonly class DemoQuestionRecipients implements TaskQuestionRecipientResolverInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function candidates(TaskSnapshot $task, TaskBoardSnapshot $board, object $asker): array
    {
        $candidates = [];
        foreach ($this->entityManager->getRepository(User::class)->findBy([], ['id' => 'ASC'], 20) as $user) {
            $candidates[] = new TaskQuestionRecipientCandidate('user:' . $user->getId(), UserLabel::of($user));
        }
        $candidates[] = new TaskQuestionRecipientCandidate('contact:client', 'Client (external)', true);

        return $candidates;
    }

    public function subjectIdsOf(object $user, ?string $tenantKey): array
    {
        return $user instanceof User ? ['user:' . $user->getId()] : [];
    }
}
