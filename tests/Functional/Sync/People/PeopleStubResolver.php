<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Sync\People;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonMatch;
use Nowo\TaskBoardBundle\Sync\Hook\SyncPersonResolverInterface;
use Nowo\TaskBoardBundle\Sync\People\SyncUser;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePerson;
use Nowo\TaskBoardBundle\Sync\Provider\RemotePersonRef;

use function in_array;

/**
 * A scriptable host resolver for the people tests: one scope per board (default "scope:a"), e-mail matches with a
 * verified flag, candidates by id, and users that are no candidate.
 */
final class PeopleStubResolver implements SyncPersonResolverInterface
{
    /** @var array<string, string> board id => scope */
    public array $scopes = [];

    /** @var array<string, array{0: object, 1: bool}> normalised e-mail => [user, verified] */
    public array $matches = [];

    /** @var list<object> */
    public array $users = [];

    /** @var list<string> user ids that are no candidate */
    public array $notCandidates = [];

    /** @var list<string> e-mails autoMatch() was asked for */
    public array $asked = [];

    public function directoryScope(TaskBoard $board): string
    {
        return $this->scopes[$board->getId()] ?? 'scope:a';
    }

    public function candidates(TaskBoard $board, string $query = '', int $limit = 20): iterable
    {
        return $this->users;
    }

    public function isCandidate(TaskBoard $board, object $user): bool
    {
        return !in_array(SyncUser::id($user), $this->notCandidates, true);
    }

    public function autoMatch(TaskBoard $board, RemotePerson|RemotePersonRef $person, ?string $normalizedEmail = null): ?SyncPersonMatch
    {
        $normalizedEmail ??= SyncUser::normalizeEmail($person->email) ?? '';
        $this->asked[] = $normalizedEmail;
        $match         = $this->matches[$normalizedEmail] ?? null;

        return $match === null ? null : new SyncPersonMatch($match[0], $match[1]);
    }

    public function candidateById(TaskBoard $board, string $userId): ?object
    {
        foreach ($this->users as $user) {
            if (SyncUser::id($user) === $userId && $this->isCandidate($board, $user)) {
                return $user;
            }
        }

        return null;
    }
}
