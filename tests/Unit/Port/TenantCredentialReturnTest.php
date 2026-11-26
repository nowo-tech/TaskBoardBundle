<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Port;

use Nowo\TaskBoardBundle\Port\Internal\TenantCredentialReturnResolverInterface;
use Nowo\TaskBoardBundle\Port\Ports;
use Nowo\TaskBoardBundle\Sync\Hook\ProviderCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialReturnAwareSourceInterface;
use Nowo\TaskBoardBundle\Sync\Hook\TenantCredentialSourceInterface;
use Nowo\TaskBoardBundle\Sync\Provider\ProviderCredential;
use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TenantRef;
use PHPUnit\Framework\TestCase;

/**
 * Since 3.4.0 (Beacon request): the token page's way back — asked of the host's service only when it implements
 * `TenantCredentialReturnAwareSourceInterface`; otherwise the plain page, as before (no BC break).
 */
final class TenantCredentialReturnTest extends TestCase
{
    public function testAReturnAwareSourceGetsTheWayBack(): void
    {
        $resolver = Ports::credentialResolver(new ReturnAwareSource());
        self::assertInstanceOf(TenantCredentialReturnResolverInterface::class, $resolver);
        self::assertSame('/tokens/acme?returnTo=/back', $resolver->tenantCredentialFormUrlWithReturn(new TenantRef('acme', 'acme'), 'clickup', '/back'));
        self::assertSame('/tokens/acme?returnTo=', $resolver->tenantCredentialFormUrlWithReturn(new TenantRef('acme', 'acme'), 'clickup', null));
    }

    public function testAPlainSourceKeepsItsPage(): void
    {
        $resolver = Ports::credentialResolver(new PlainTenantSource());
        self::assertInstanceOf(TenantCredentialReturnResolverInterface::class, $resolver);
        self::assertSame('/tokens/acme', $resolver->tenantCredentialFormUrlWithReturn(new TenantRef('acme', 'acme'), 'clickup', '/back'));
    }
}

class PlainTenantSource implements ProviderCredentialSourceInterface, TenantCredentialSourceInterface
{
    public function connectionsFor(TaskBoardSnapshot $board, object $user, string $provider): array
    {
        return [];
    }

    public function credentialFor(TaskBoardSnapshot $board, string $connectionRef): ?ProviderCredential
    {
        return null;
    }

    public function credentialFormUrl(TaskBoardSnapshot $board, string $provider): ?string
    {
        return null;
    }

    public function tenantConnectionsFor(TenantRef $tenant, object $user, string $provider): array
    {
        return [];
    }

    public function tenantCredentialFor(TenantRef $tenant, string $connectionRef): ?ProviderCredential
    {
        return null;
    }

    public function tenantCredentialFormUrl(TenantRef $tenant, string $provider): ?string
    {
        return '/tokens/' . $tenant->key;
    }

    public function tenantConnectUrl(TenantRef $tenant, string $provider, string $returnTo): ?string
    {
        return null;
    }
}

final class ReturnAwareSource extends PlainTenantSource implements TenantCredentialReturnAwareSourceInterface
{
    public function tenantCredentialFormUrlWithReturn(TenantRef $tenant, string $provider, ?string $returnTo): string
    {
        return '/tokens/' . $tenant->key . '?returnTo=' . $returnTo;
    }
}
