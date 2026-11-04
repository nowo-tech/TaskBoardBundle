<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Avatar;

/**
 * Since 1.23: no pictures — every member keeps the initial chip.
 */
final readonly class NullTaskMemberAvatarResolver implements TaskMemberAvatarResolverInterface
{
    public function urlFor(object $user, int $size): ?string
    {
        return null;
    }

    public function urlsFor(array $users, int $size): array
    {
        return [];
    }
}
