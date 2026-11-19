<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Search\Header;

use Nowo\TaskBoardBundle\Search\Header\SearchTenants;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Since 2.25.0 (audit VL-3): the tenant of a key once per viewer and request. */
final class SearchTenantsTest extends TestCase
{
    public function testAsksTheResolverOncePerKeyAndViewerWithinARequest(): void
    {
        $calls    = 0;
        $resolver = $this->createMock(TaskBoardTenantResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (string $slug) use (&$calls): ?TaskBoardTenant {
            ++$calls;

            return $slug === 'acme' ? new TaskBoardTenant('acme', 'acme', 'Acme') : null;
        });
        $stack   = new RequestStack();
        $context = new TaskBoardTenantContext($stack, $resolver, true);
        $tenants = new SearchTenants($context, $stack);
        $alice   = new TestUser('1', 'a@example.com');

        self::assertNull((new SearchTenants())->tenantOfKey('acme', $alice), 'no tenancy');
        self::assertSame('acme', $tenants->tenantOfKey('acme', $alice)?->key, 'outside a request: asked every time');
        self::assertSame(1, $calls);

        $stack->push(new Request());
        self::assertSame('acme', $tenants->tenantOfKey('acme', $alice)?->key);
        self::assertSame('acme', $tenants->tenantOfKey('acme', $alice)?->key);
        self::assertNull($tenants->tenantOfKey('globex', $alice));
        self::assertNull($tenants->tenantOfKey('globex', $alice));
        self::assertSame(3, $calls, 'once per key');
        self::assertSame('acme', $tenants->tenantOfKey('acme', new TestUser('2', 'b@example.com'))?->key);
        self::assertSame('acme', $tenants->tenantOfKey('acme', new stdClass())?->key);
        self::assertSame(5, $calls, 'once per viewer');

        $stack->push(new Request());
        $tenants->tenantOfKey('acme', $alice);
        self::assertSame(6, $calls, 'a new request asks again');
    }
}
