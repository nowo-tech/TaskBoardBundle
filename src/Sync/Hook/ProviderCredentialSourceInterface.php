<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of {@see ProviderCredentialResolverInterface}, given the
 * board as a {@see TaskBoardSnapshot}. The host keeps the provider tokens; the bundle only knows their opaque reference.
 *
 * Set `sync.credential_resolver` to a service implementing it. A service that also implements the deprecated interface is
 * asked through that one until 3.0.
 */
interface ProviderCredentialSourceInterface
{
    /**
     * The connections of a provider the user may use on the board (the picker).
     *
     * @return list<ConnectionChoice>
     */
    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array;

    /** The credential of a connection for one run; null when it is gone or no longer allowed for this board. */
    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential;

    /** The host page where a token is pasted (linked from the connection page); null = none. */
    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string;
}
