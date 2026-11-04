<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * Since 1.23: the host's user lookup by e-mail, told the board (the sync kernel aliases the import resolver to it).
 */
final class BoardAwareUserResolver implements BoardAwareTaskImportUserResolverInterface
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

    public function resolveByEmailForBoard(string $email, TaskBoard $board): ?object
    {
        self::$boards[] = $board->getId();

        return $this->resolveByEmail($email);
    }
}
