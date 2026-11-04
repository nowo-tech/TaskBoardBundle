<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface;
use Nowo\TaskBoardBundle\Avatar\TaskMemberKey;

/**
 * Since 1.23: the host's avatar resolver in the functional kernel — no picture unless a test maps an e-mail to a URL;
 * records every call and its users (to prove one call per response, after the access filters).
 */
final class CountingAvatarResolver implements TaskMemberAvatarResolverInterface
{
    /** @var array<string, string> e-mail => URL */
    public static array $urls = [];

    /** @var list<list<string>> the e-mails of each urlsFor() call */
    public static array $batches = [];

    public static int $single = 0;

    public function urlFor(object $user, int $size): ?string
    {
        ++self::$single;

        return null;
    }

    public function urlsFor(array $users, int $size): array
    {
        self::$batches[] = array_map(static fn (object $user): string => (string) $user->getUserIdentifier(), $users);
        $urls            = [];
        foreach ($users as $user) {
            $urls[(string) TaskMemberKey::of($user)] = self::$urls[$user->getUserIdentifier()] ?? null;
        }

        return $urls;
    }

    public static function reset(): void
    {
        self::$urls    = [];
        self::$batches = [];
        self::$single  = 0;
    }
}
