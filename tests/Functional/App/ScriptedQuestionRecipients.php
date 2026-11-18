<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientCandidate;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientResolverInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Beacon spec 242: a host resolver — every other user as `user:<id>`, and three external client contacts (one offered twice: kept once).
 */
final readonly class ScriptedQuestionRecipients implements TaskQuestionRecipientResolverInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function candidates(TaskSnapshot $task, TaskBoardSnapshot $board, object $asker): array
    {
        $candidates = [];
        foreach ($this->entityManager->getRepository(AppUser::class)->findBy([], ['id' => 'ASC']) as $user) {
            if ($user->getId() !== ($asker instanceof AppUser ? $asker->getId() : null)) {
                $candidates[] = new TaskQuestionRecipientCandidate('user:' . $user->getId(), $user->getUserIdentifier());
            }
        }
        // Dropped by the bundle: an id that cannot be matched exactly.
        $candidates[] = new TaskQuestionRecipientCandidate(' padded ', 'Look-alike');
        foreach (['ok', 'bad', 'none', 'ok'] as $contact) {
            $candidates[] = new TaskQuestionRecipientCandidate('contact:' . $contact, 'Client ' . $contact, true);
        }

        return $candidates;
    }

    public function subjectIdsOf(object $user, ?string $tenantKey): array
    {
        return $user instanceof AppUser ? ['user:' . $user->getId()] : [];
    }
}
