<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;

/**
 * Since 3.2.0 (CU-2, optional): implemented by the same service as {@see ProviderCredentialSourceInterface}
 * (`sync.credential_resolver`) when the host offers the guided import from ClickUp. The import starts from a tenant,
 * before any of its boards exists, so the connections are asked per tenant; once a board is created the bundle asks
 * {@see ProviderCredentialSourceInterface::credentialFor()} for it as usual, with the same reference.
 *
 * Without it the import plan pages answer 404. A connection given here must be usable on every board of the tenant.
 */
interface TenantCredentialSourceInterface
{
    /**
     * The connections of a provider the user may use in the tenant (the Connect step).
     *
     * @return list<ConnectionChoice>
     */
    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array;

    /** The credential of a connection for the wizard's reads; null when it is gone or not allowed in the tenant. */
    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential;

    /** The host page where a token is pasted for the tenant; null = none. */
    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): ?string;

    /**
     * The host's OAuth start route for the tenant (`https://` or a same-origin path), or null. After the callback the
     * host redirects to `$returnTo`, the plan's Connect step (a same-origin path, check it with
     * `OAuthReturnPath::isSafe()`).
     */
    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string;
}
