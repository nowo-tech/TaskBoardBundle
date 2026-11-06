<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\AttributionOnlyResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function in_array;

/** Since 1.28.0: {@see ScriptedPeopleResolver} plus attribution-only users (a host's suspended members). */
final class AttributionPeopleResolver implements SyncPersonResolverInterface, AttributionOnlyResolverInterface
{
    /** @var list<string> */
    public static array $attributionOnly = [];

    private readonly ScriptedPeopleResolver $inner;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->inner = new ScriptedPeopleResolver($entityManager);
    }

    public function isAttributionOnly(TaskBoard $board, object $user): bool
    {
        return $user instanceof AppUser && in_array($user->getEmail(), self::$attributionOnly, true);
    }

    public function directoryScope(TaskBoard $board): string
    {
        return $this->inner->directoryScope($board);
    }

    public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable
    {
        return $this->inner->candidates($board, $query, $limit);
    }

    public function isCandidate(TaskBoard $board, object $user): bool
    {
        return $this->inner->isCandidate($board, $user);
    }

    public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
    {
        return $this->inner->autoMatch($board, $person, $normalizedEmail);
    }

    public function candidateById(TaskBoard $board, string $userId): ?object
    {
        return $this->inner->candidateById($board, $userId);
    }
}
