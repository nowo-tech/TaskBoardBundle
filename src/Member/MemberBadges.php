<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Member;

use Nowo\TaskBoardBundle\Avatar\TaskMemberKey;
use Nowo\TaskBoardBundle\EventListener\TaskBoardRequestBoundarySubscriber;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardUserRef;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_exists;
use function array_values;
use function is_array;

/**
 * @internal since 2.19.0 (Beacon spec 234): the member badges of one request. {@see self::prime()} asks the host's
 * provider once for the people a page shows ({@see MemberBadgeProviderInterface::badgesForMany()}),
 * {@see self::of()} answers from the memo (a miss primes that one member). Anything but a {@see MemberBadge} in an
 * answer is dropped; a failing provider gives no badge and a warning. Without a provider nothing is asked or kept.
 * Cleared at every request, message and command boundary ({@see TaskBoardRequestBoundarySubscriber}) and by
 * `kernel.reset`: no badge of one request is ever shown in another (FrankenPHP worker mode).
 */
final class MemberBadges implements ResetInterface
{
    /** @var array<string, list<MemberBadge>> */
    private array $badges = [];

    public function __construct(
        private readonly ?MemberBadgeProviderInterface $provider = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param iterable<object|null> $users users (or user references) shown by a page
     */
    public function prime(iterable $users): void
    {
        if (!$this->provider instanceof MemberBadgeProviderInterface) {
            return;
        }
        $missing = [];
        foreach ($users as $user) {
            $key = $user === null ? null : $this->keyOf($user);
            if ($key !== null && !array_key_exists($key, $this->badges) && !isset($missing[$key])) {
                $missing[$key] = new TaskBoardUserRef($key);
            }
        }
        if ($missing === []) {
            return;
        }
        try {
            $answer = $this->provider->badgesForMany(array_values($missing));
        } catch (Throwable $exception) {
            $this->logger->warning('TaskBoard member badge provider failed', ['bundle' => 'nowo_task_board', 'exception' => $exception::class]);
            $answer = [];
        }
        foreach ($missing as $key => $ref) {
            $found = $answer[$key] ?? [];
            $list  = [];
            foreach (is_array($found) ? $found : [] as $badge) {
                if ($badge instanceof MemberBadge) {
                    $list[] = $badge;
                }
            }
            // @igor-ignore - Request memo, cleared by reset() at every boundary (worker mode).
            $this->badges[$key] = $list;
        }
    }

    /**
     * @return list<MemberBadge>
     */
    public function of(?object $user): array
    {
        if (!$this->provider instanceof MemberBadgeProviderInterface || $user === null) {
            return [];
        }
        $key = $this->keyOf($user);
        if ($key === null) {
            return [];
        }
        if (!array_key_exists($key, $this->badges)) {
            $this->prime([$user]);
        }

        return $this->badges[$key] ?? [];
    }

    public function reset(): void
    {
        // @igor-ignore - Request memo, cleared between the requests of a worker.
        $this->badges = [];
    }

    /** The member's id, when it is one a {@see TaskBoardUserRef} can carry exactly; null otherwise (no badge). */
    private function keyOf(object $user): ?string
    {
        $key = $user instanceof TaskBoardUserRef ? $user->id : TaskMemberKey::of($user);

        return $key !== null && TaskBoardUserRef::isValid($key) ? $key : null;
    }
}
