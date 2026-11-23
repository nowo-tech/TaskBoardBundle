<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Internal\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function assert;

/**
 * Assignee provider of the functional kernel (`assignee_provider: app.assignee_provider`): only a board's creator may
 * be assigned; the users of `$offeredEmails` are listed in the picker anyway (a stale list), so the refusal path is
 * reachable.
 */
final class OwnerOnlyAssigneeProvider implements TaskAssigneeProviderInterface
{
    /** @var list<string> */
    public static array $offeredEmails = [];

    private readonly EntityManagerInterface $entityManager;

    /**
     * Since 3.0.0: the application's manager of the users (a test stub lives in the bundle's namespace, so a plain
     * EntityManagerInterface would be moved onto TaskBoard's private manager; a host's own service never is).
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $manager = $doctrine->getManagerForClass(AppUser::class);
        assert($manager instanceof EntityManagerInterface);
        $this->entityManager = $manager;
    }

    public function assignableUsers(TaskBoard $board): array
    {
        $offered = self::$offeredEmails === [] ? [] : $this->entityManager->getRepository(AppUser::class)->findBy(['email' => self::$offeredEmails]);
        // Since 3.0.0 the creator read from the board is TaskBoard's (private manager) instance: a host lists its own
        // users, read from its own manager (the member picker's choices must be managed by it).
        $creator = $board->getCreator();
        $creator = $creator instanceof AppUser ? $this->entityManager->find(AppUser::class, $creator->getId()) : null;

        return [...($creator instanceof AppUser ? [$creator] : []), ...$offered];
    }

    public function canBeAssigned(TaskBoard $board, object $user): bool
    {
        // Compared by id (audit OC-L1): the creator read from the board is the private manager's instance.
        $creator = $board->getCreator();

        return $user instanceof AppUser && $creator instanceof AppUser && $user->getId() === $creator->getId();
    }
}
