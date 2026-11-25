<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Port\Internal\OAuthConnectionStarterInterface;
use Nowo\TaskBoardBundle\Port\Internal\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\TenantCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\OAuthConnectionStartInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;

final readonly class TwinCredentialSource implements ProviderCredentialSourceInterface, OAuthConnectionStartInterface, TenantCredentialSourceInterface
{
    public function __construct(private ProviderCredentialResolverInterface $legacy, private TwinLookup $lookup)
    {
    }

    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        return $this->legacy->connectionsFor($this->lookup->board($board->id), $user, $provider);
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        return $this->legacy->credentialFor($this->lookup->board($board->id), $connectionRef);
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return $this->legacy->credentialFormUrl($this->lookup->board($board->id), $provider);
    }

    public function connectUrl(TaskBoardSnapshot $board, string $provider, string $returnTo): ?string
    {
        return $this->legacy instanceof OAuthConnectionStarterInterface ? $this->legacy->connectUrl($this->lookup->board($board->id), $provider, $returnTo) : null;
    }

    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array
    {
        return $this->legacy instanceof TenantCredentialResolverInterface ? $this->legacy->tenantConnectionsFor($tenant, $user, $provider) : [];
    }

    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential
    {
        return $this->legacy instanceof TenantCredentialResolverInterface ? $this->legacy->tenantCredentialFor($tenant, $connectionRef) : null;
    }

    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): ?string
    {
        return $this->legacy instanceof TenantCredentialResolverInterface ? $this->legacy->tenantCredentialFormUrl($tenant, $provider) : null;
    }

    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string
    {
        return $this->legacy instanceof TenantCredentialResolverInterface ? $this->legacy->tenantConnectUrl($tenant, $provider, $returnTo) : null;
    }
}
