<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Port\Internal\OAuthConnectionStarterInterface;
use Nowo\TaskBoardBundle\Port\Internal\ProviderCredentialResolverInterface;
use Nowo\TaskBoardBundle\Port\Internal\TenantCredentialResolverInterface;
use Nowo\TaskBoardBundle\Sync\Hook\ConnectionChoice;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;

/**
 * Since 1.23: the host's credential store of the functional kernel — one connection "conn-1" whose secret tests may
 * change (the canary token test). Since 3.1.0 it offers an OAuth start route when {@see self::$connectUrl} is set.
 * Since 3.2.0 it answers per tenant too (import plans) while {@see self::$tenants} is true.
 */
final class TestCredentialResolver implements ProviderCredentialResolverInterface, OAuthConnectionStarterInterface, TenantCredentialResolverInterface
{
    public static string $secret = 'pk_test_token';

    public static bool $available = true;

    public static string $formUrl = '/settings/connections/clickup';

    public static ?string $connectUrl = null;

    public static bool $tenants = true;

    /** The tenant of the last tenant call. */
    public static ?TenantRef $tenant = null;

    /** The `returnTo` of the last {@see self::connectUrl()} call. */
    public static ?string $returnTo = null;

    /** @var list<ConnectionChoice>|null */
    public static ?array $choices = null;

    public function connectionsFor(TaskBoard $board, object $user, string $provider): array
    {
        return self::$choices ?? [new ConnectionChoice('conn-1', 'Team token', '…' . substr(self::$secret, -4))];
    }

    public function credentialFor(TaskBoard $board, string $connectionRef): ?ProviderCredential
    {
        return self::$available && $connectionRef === 'conn-1' ? new ProviderCredential(self::$secret, $connectionRef) : null;
    }

    public function credentialFormUrl(TaskBoard $board, string $provider): string
    {
        return self::$formUrl;
    }

    public function connectUrl(TaskBoard $board, string $provider, string $returnTo): ?string
    {
        self::$returnTo = $returnTo;

        return self::$connectUrl;
    }

    public function supportsTenants(): bool
    {
        return self::$tenants;
    }

    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array
    {
        self::$tenant = $tenant;

        return self::$choices ?? [new ConnectionChoice('conn-1', 'Team token', '…' . substr(self::$secret, -4))];
    }

    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential
    {
        return self::$available && $connectionRef === 'conn-1' ? new ProviderCredential(self::$secret, $connectionRef) : null;
    }

    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): string
    {
        return self::$formUrl;
    }

    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string
    {
        self::$returnTo = $returnTo;

        return self::$connectUrl;
    }

    public static function reset(): void
    {
        self::$tenants    = true;
        self::$tenant     = null;
        self::$connectUrl = null;
        self::$returnTo   = null;
        self::$secret     = 'pk_test_token';
        self::$available  = true;
        self::$formUrl    = '/settings/connections/clickup';
        self::$choices    = null;
    }
}
