<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Import\BoardScopedTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 1.23: the host's user lookup by e-mail, told the board (the sync kernel aliases the import resolver to it). Since
 * 3.0.0 on the board-scoped interface (the board-aware 2.14 one was removed).
 */
final class BoardAwareUserResolver implements BoardScopedTaskImportUserResolverInterface
{
    /** @var list<string> */
    public static array $boards = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function resolveByEmail(string $email): ?object
    {
        return $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => $email]);
    }

    public function resolveByEmailOnBoard(string $email, TaskBoardSnapshot $board): ?object
    {
        self::$boards[] = $board->id;

        return $this->resolveByEmail($email);
    }
}
