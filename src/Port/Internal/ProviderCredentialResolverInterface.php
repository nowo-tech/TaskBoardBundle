<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

/**
 * Since 1.23 (required with `sync.enabled`): the host keeps the provider tokens; the bundle only knows their
 * opaque reference.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see ProviderCredentialSourceInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface ProviderCredentialResolverInterface
{
    /**
     * The connections of a provider the user may use on the board (the picker).
     *
     * @return list<ConnectionChoice>
     */
    public function connectionsFor(TaskBoard $board, object $user, string $provider): array;

    /** The credential of a connection for one run; null when it is gone or no longer allowed for this board. */
    public function credentialFor(TaskBoard $board, string $connectionRef): ?ProviderCredential;

    /** The host page where a token is pasted (linked from the connection page); null = none. */
    public function credentialFormUrl(TaskBoard $board, string $provider): ?string;
}
