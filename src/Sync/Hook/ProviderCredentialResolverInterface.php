<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

/**
 * Since 1.23 (required with `sync.enabled`): the host keeps the provider tokens; the bundle only knows their
 * opaque reference.
 *
 * @deprecated since 2.15.0, implement the framework-free {@see ProviderCredentialSourceInterface} instead (REQ-ARCH-001); removed in 3.0. A
 *             service implementing this interface is still asked through it, exactly as before.
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
