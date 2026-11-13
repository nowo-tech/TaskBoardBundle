<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;

/**
 * Since 2.15.0: asks a host {@see ProviderCredentialSourceInterface} where the bundle asks the deprecated
 * {@see ProviderCredentialResolverInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class ProviderCredentialSourceGate implements ProviderCredentialResolverInterface
{
    public function __construct(private ProviderCredentialSourceInterface $source)
    {
    }

    public function connectionsFor(TaskBoard $board, object $user, string $provider): array
    {
        return $this->source->connectionsFor(Snapshots::board($board), $user, $provider);
    }

    public function credentialFor(TaskBoard $board, string $connectionRef): ?ProviderCredential
    {
        return $this->source->credentialFor(Snapshots::board($board), $connectionRef);
    }

    public function credentialFormUrl(TaskBoard $board, string $provider): ?string
    {
        return $this->source->credentialFormUrl(Snapshots::board($board), $provider);
    }
}
