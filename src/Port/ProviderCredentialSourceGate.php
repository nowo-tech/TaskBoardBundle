<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Internal\OAuthConnectionStarterInterface;
use Nowo\TaskBoardBundle\Port\Internal\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\TenantCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\OAuthConnectionStartInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;

/**
 * Since 2.15.0: asks a host {@see ProviderCredentialSourceInterface} where the bundle asks its internal seam
 * {@see ProviderCredentialResolverInterface}. Since 3.1.0 it also answers {@see OAuthConnectionStarterInterface} when
 * the host's service implements {@see OAuthConnectionStartInterface} (else no OAuth start), and since 3.2.0
 * {@see TenantCredentialResolverInterface} when it implements {@see TenantCredentialSourceInterface} (else no import
 * plans). Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class ProviderCredentialSourceGate implements ProviderCredentialResolverInterface, OAuthConnectionStarterInterface, TenantCredentialResolverInterface
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

    public function connectUrl(TaskBoard $board, string $provider, string $returnTo): ?string
    {
        return $this->source instanceof OAuthConnectionStartInterface ? $this->source->connectUrl(Snapshots::board($board), $provider, $returnTo) : null;
    }

    public function supportsTenants(): bool
    {
        return $this->source instanceof TenantCredentialSourceInterface;
    }

    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array
    {
        return $this->source instanceof TenantCredentialSourceInterface ? $this->source->tenantConnectionsFor($tenant, $user, $provider) : [];
    }

    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential
    {
        return $this->source instanceof TenantCredentialSourceInterface ? $this->source->tenantCredentialFor($tenant, $connectionRef) : null;
    }

    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): ?string
    {
        return $this->source instanceof TenantCredentialSourceInterface ? $this->source->tenantCredentialFormUrl($tenant, $provider) : null;
    }

    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string
    {
        return $this->source instanceof TenantCredentialSourceInterface ? $this->source->tenantConnectUrl($tenant, $provider, $returnTo) : null;
    }
}
