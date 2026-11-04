<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\User;
use Nowo\TaskBoardBundle\Avatar\TaskMemberAvatarResolverInterface;
use Nowo\TaskBoardBundle\Avatar\TaskMemberKey;

/**
 * Demo (since 1.23): member pictures from public/avatars — the demo user and Ada have one, Grace's URL is broken on
 * purpose (the chip falls back to her initial). A real host returns a stable, access-checked URL of its file storage.
 */
final readonly class DemoAvatarResolver implements TaskMemberAvatarResolverInterface
{
    private const PICTURES = [
        'demo@example.com'  => '/avatars/demo.png',
        'ada@example.com'   => '/avatars/ada.png',
        'grace@example.com' => '/avatars/missing.png',
    ];

    public function urlFor(object $user, int $size): ?string
    {
        return $user instanceof User ? (self::PICTURES[$user->getEmail()] ?? null) : null;
    }

    public function urlsFor(array $users, int $size): array
    {
        $urls = [];
        foreach ($users as $user) {
            $key = TaskMemberKey::of($user);
            if ($key !== null) {
                $urls[$key] = $this->urlFor($user, $size);
            }
        }

        return $urls;
    }
}
