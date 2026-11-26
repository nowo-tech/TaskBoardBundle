<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;

/**
 * Since 3.2.0: the bundle's seam of {@see TenantCredentialSourceInterface}, implemented by the credential source's gate
 * (and by test doubles). {@see self::supportsTenants()} is false when the host's service does not implement it.
 *
 * @internal
 */
interface TenantCredentialResolverInterface
{
    public function supportsTenants(): bool;

    /**
     * @return list<ConnectionChoice>
     */
    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array;

    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential;

    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): ?string;

    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string;
}
