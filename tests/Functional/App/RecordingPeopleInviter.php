<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\PeopleInviterInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * Since 1.27.0: the host's inviter of the People page tests — records the invitations; an e-mail of an existing user is
 * answered with that user (mapped at once), any other one is pending.
 */
final class RecordingPeopleInviter implements PeopleInviterInterface
{
    /** @var list<string> */
    public static array $invited = [];

    public static bool $allowed = true;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function reset(): void
    {
        self::$invited = [];
        self::$allowed = true;
    }

    public function canInvite(TaskBoard $board, object $actor): bool
    {
        return self::$allowed;
    }

    public function invite(TaskBoard $board, string $email, string $name, object $actor): ?object
    {
        self::$invited[] = $email;

        return $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => $email]);
    }
}
