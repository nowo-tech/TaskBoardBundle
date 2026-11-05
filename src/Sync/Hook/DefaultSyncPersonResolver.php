<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Assignee\TaskAssigneeProviderInterface;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\BoardAwareTaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Import\TaskImportUserResolverInterface;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

use function array_slice;
use function count;
use function method_exists;
use function str_contains;

/**
 * Since 1.25: the people resolver when the host configures none — 1.23 semantics. The scope is the board itself (no
 * sharing); candidates are the board's assignable users (none without an assignee provider); the automatic match is
 * the 1.23 e-mail lookup. `verified` is the user's `isVerified()` when it has one, else `!require_verified_email`.
 */
final readonly class DefaultSyncPersonResolver implements SyncPersonResolverInterface
{
    public function __construct(
        private TaskImportUserResolverInterface $userResolver,
        private EntityManagerInterface $entityManager,
        private string $userClass,
        private ?TaskAssigneeProviderInterface $assigneeProvider = null,
        private bool $requireVerifiedEmail = true,
    ) {
    }

    public function directoryScope(TaskBoard $board): string
    {
        return 'board:' . $board->getId();
    }

    public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable
    {
        if (!$this->assigneeProvider instanceof TaskAssigneeProviderInterface) {
            return [];
        }
        $query = SyncUser::normalizeEmail($query) ?? '';
        $found = [];
        foreach ($this->assigneeProvider->assignableUsers($board) as $user) {
            if ($query === '' || str_contains(SyncUser::normalizeEmail(UserLabel::of($user)) ?? '', $query)) {
                $found[] = $user;
            }
            if (count($found) >= $limit) {
                break;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function isCandidate(TaskBoard $board, object $user): bool
    {
        return !$this->assigneeProvider instanceof TaskAssigneeProviderInterface || $this->assigneeProvider->canBeAssigned($board, $user);
    }

    public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, string $normalizedEmail): ?SyncPersonMatch
    {
        $user = $this->userResolver instanceof BoardAwareTaskImportUserResolverInterface
            ? $this->userResolver->resolveByEmailForBoard($normalizedEmail, $board)
            : $this->userResolver->resolveByEmail($normalizedEmail);
        if ($user === null || !$this->isCandidate($board, $user)) {
            return null;
        }

        return new SyncPersonMatch($user, method_exists($user, 'isVerified') ? $user->isVerified() === true : !$this->requireVerifiedEmail);
    }

    public function candidateById(TaskBoard $board, string $userId): ?object
    {
        /** @var class-string $class */
        $class = $this->userClass;
        $user  = $this->entityManager->find($class, $userId);

        return $user !== null && $this->isCandidate($board, $user) ? $user : null;
    }
}
