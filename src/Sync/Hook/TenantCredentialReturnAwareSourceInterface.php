<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\ValueObject\TenantRef;

/**
 * Since 3.4.0 (Beacon request, optional): implemented by the same service as {@see TenantCredentialSourceInterface}
 * (`sync.credential_resolver`) when the host's page that takes a pasted token can send the person back to the import
 * plan afterwards. The bundle then asks this method instead of
 * {@see TenantCredentialSourceInterface::tenantCredentialFormUrl()} for the Connect step's link, with `$returnTo` = the
 * plan's Connect step: a same-origin path the bundle already checked with `OAuthReturnPath::isSafe()`. Check it again
 * when you keep it (the request that brings it back is the browser's), then redirect there after the token is saved.
 *
 * Without it nothing changes: the link is `tenantCredentialFormUrl()`, as in 3.2.0 and 3.3.0. `TenantCredentialSourceInterface`
 * itself is unchanged (no BC break).
 */
interface TenantCredentialReturnAwareSourceInterface
{
    /**
     * The host page where a token is pasted for the tenant, which sends the person back to `$returnTo` afterwards;
     * null = none. `$returnTo` null: no way back (answer as `tenantCredentialFormUrl()` would).
     */
    public function tenantCredentialFormUrlWithReturn(TenantRef $tenant, string $provider, ?string $returnTo): ?string;
}
