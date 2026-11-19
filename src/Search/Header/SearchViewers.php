<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.25.0 (header search): the viewer of a search made by user identifier. Only the signed-in user is known: an
 * identifier that is not theirs (a search run for someone else, or outside a request) has no viewer, so it finds
 * nothing. Stateless: the token is read on every call (FrankenPHP worker mode).
 */
final readonly class SearchViewers
{
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * The signed-in user as a viewer when their identifier is exactly `$userIdentifier`, else null.
     */
    public function forIdentifier(string $userIdentifier, ?string $scope = null): ?SearchViewer
    {
        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface || $userIdentifier === '' || $user->getUserIdentifier() !== $userIdentifier) {
            return null;
        }

        return new SearchViewer($user, true, $scope);
    }
}
