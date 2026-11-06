<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;
use Nowo\TaskBoardBundle\Tests\Functional\App\Entity\AppUser;

use function in_array;

/**
 * Since 1.27.0: the host's people resolver of the People page tests — the candidates are the users whose e-mail is in
 * {@see self::$candidates}; {@see self::$verified} e-mails match automatically (verified); one directory per board.
 */
final class ScriptedPeopleResolver implements SyncPersonResolverInterface
{
    /** @var list<string> */
    public static array $candidates = [];

    /** @var list<string> */
    public static array $verified = [];

    /** @var list<string> e-mails matched but not verified (suggested) */
    public static array $unverified = [];

    /** @var list<string> e-mails candidateById() refuses although candidates() lists them */
    public static array $refuseById = [];

    /** A scope shared by every board (null = one directory per board). */
    public static ?string $scope = null;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public static function reset(): void
    {
        self::$candidates = [];
        self::$verified   = [];
        self::$unverified = [];
        self::$refuseById = [];
        self::$scope      = null;
    }

    public function directoryScope(TaskBoard $board): string
    {
        return self::$scope ?? 'board:' . $board->getId();
    }

    public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable
    {
        return $this->entityManager->getRepository(AppUser::class)->findBy(['email' => self::$candidates], ['email' => 'ASC'], $limit);
    }

    public function isCandidate(TaskBoard $board, object $user): bool
    {
        return $user instanceof AppUser && in_array($user->getEmail(), self::$candidates, true);
    }

    public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
    {
        $normalizedEmail ??= SyncUser::normalizeEmail($person->email);
        $verified = in_array($normalizedEmail, self::$verified, true);
        if ($normalizedEmail === null || (!$verified && !in_array($normalizedEmail, self::$unverified, true))) {
            return null;
        }
        $user = $this->entityManager->getRepository(AppUser::class)->findOneBy(['email' => $normalizedEmail]);

        return $user instanceof AppUser && $this->isCandidate($board, $user) ? new SyncPersonMatch($user, $verified) : null;
    }

    public function candidateById(TaskBoard $board, string $userId): ?object
    {
        $user = $this->entityManager->find(AppUser::class, (int) $userId);

        return $user instanceof AppUser && $this->isCandidate($board, $user) && !in_array($user->getEmail(), self::$refuseById, true) ? $user : null;
    }
}
