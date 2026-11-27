<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialReturnAwareSourceInterface;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;

/**
 * Since 3.4.0: the bundle's seam of {@see TenantCredentialReturnAwareSourceInterface}, implemented by the credential
 * source's gate. When the host's service does not implement it, the gate answers the plain token page.
 *
 * @internal
 */
interface TenantCredentialReturnResolverInterface
{
    /** The token page of the tenant with a way back to `$returnTo` (already checked same-origin), or the plain one. */
    public function tenantCredentialFormUrlWithReturn(TenantRef $tenant, string $provider, ?string $returnTo): ?string;
}
