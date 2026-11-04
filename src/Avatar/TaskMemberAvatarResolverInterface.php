<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Avatar;

/**
 * Since 1.23: the URL of a task member's profile picture. The application aliases this interface; the default returns
 * null (the initial chip). The URL must be stable and access-checked when fetched, `https:` or a same-origin path;
 * anything else is dropped ({@see AvatarUrlPolicy}).
 */
interface TaskMemberAvatarResolverInterface
{
    /**
     * @param object $user an instance of `user_class`
     */
    public function urlFor(object $user, int $size): ?string;

    /**
     * One call per rendered page or fragment. Must not run a query per user.
     *
     * @param list<object> $users distinct instances of `user_class`, never empty
     *
     * @return array<string, string|null> {@see TaskMemberKey::of()} => URL or null (a missing key = null)
     */
    public function urlsFor(array $users, int $size): array;
}
