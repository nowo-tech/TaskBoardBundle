<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Event\SearchScopeEvent;

/**
 * Since 2.25.0 (header search): who searches, as the header search services get it.
 *
 * `$manageRoles` says whether `security.manage_roles` may count for this viewer: those are the roles of the signed-in
 * user, so they count only when the viewer is that user ({@see SearchViewers}). `$scope` is the scope id of the search
 * box: client input, handed to the {@see SearchScopeEvent} listeners and never trusted.
 */
final readonly class SearchViewer
{
    /**
     * @param object $user The host's user object
     * @param bool $manageRoles Whether the signed-in user's manage roles apply (the viewer is that user)
     * @param string|null $scope Scope id of the search box, or null
     */
    public function __construct(
        public object $user,
        public bool $manageRoles = false,
        public ?string $scope = null,
    ) {
    }
}
