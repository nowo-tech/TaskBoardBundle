<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

/**
 * Assignee provider of the functional kernel (`assignee_provider: app.assignee_provider`): only a board's creator may
 * be assigned; the users of `$offeredEmails` are listed in the picker anyway (a stale list), so the refusal path is
 * reachable.
 */
final class OwnerOnlyAssigneeProvider implements TaskAssigneeProviderInterface
{
    /** @var list<string> */
    public static array $offeredEmails = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function assignableUsers(TaskBoard $board): array
    {
        $offered = self::$offeredEmails === [] ? [] : $this->entityManager->getRepository(AppUser::class)->findBy(['email' => self::$offeredEmails]);

        return [$board->getCreator(), ...$offered];
    }

    public function canBeAssigned(TaskBoard $board, object $user): bool
    {
        return $user === $board->getCreator();
    }
}
