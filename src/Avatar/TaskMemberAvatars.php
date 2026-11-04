<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Avatar;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_exists;
use function array_values;
use function is_string;

/**
 * @internal since 1.23: the avatar URLs of one request — {@see self::prime()} asks the host's resolver once for the
 * people a page shows (after its access filters), {@see self::urlOf()} answers from the memo (a miss primes that one
 * user). URLs go through {@see AvatarUrlPolicy}; a failing resolver gives null and a warning. Reset after each request.
 */
final class TaskMemberAvatars implements ResetInterface
{
    /** The size the bundle asks for (its chips are 24 and 32 px). */
    public const SIZE = 32;

    /** @var array<string, string|null> */
    private array $urls = [];

    public function __construct(
        private readonly TaskMemberAvatarResolverInterface $resolver = new NullTaskMemberAvatarResolver(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param iterable<object|null> $users
     */
    public function prime(iterable $users): void
    {
        $missing = [];
        foreach ($users as $user) {
            $key = $user === null ? null : TaskMemberKey::of($user);
            if ($user !== null && $key !== null && !array_key_exists($key, $this->urls) && !isset($missing[$key])) {
                $missing[$key] = $user;
            }
        }
        if ($missing === []) {
            return;
        }
        try {
            $resolved = $this->resolver->urlsFor(array_values($missing), self::SIZE);
        } catch (Throwable $exception) {
            $this->logger->warning('TaskBoard avatar resolver failed', ['bundle' => 'nowo_task_board', 'exception' => $exception::class]);
            $resolved = [];
        }
        foreach ($missing as $key => $user) {
            $url              = $resolved[$key] ?? null;
            $this->urls[$key] = is_string($url) ? AvatarUrlPolicy::sanitize($url) : null;
        }
    }

    public function urlOf(?object $user): ?string
    {
        $key = $user === null ? null : TaskMemberKey::of($user);
        if ($user === null || $key === null) {
            return null;
        }
        if (!array_key_exists($key, $this->urls)) {
            $this->prime([$user]);
        }

        return $this->urls[$key] ?? null;
    }

    public function reset(): void
    {
        $this->urls = [];
    }
}
