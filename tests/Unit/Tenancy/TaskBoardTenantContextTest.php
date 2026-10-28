<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Tenancy;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tenancy\SlugTaskBoardTenantResolver;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantResolverInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TaskBoardTenantContextTest extends TestCase
{
    public function testWithoutTenancyEveryBoardIsServed(): void
    {
        $context = new TaskBoardTenantContext($this->stack('42'), new SlugTaskBoardTenantResolver());
        $user    = new stdClass();

        self::assertFalse($context->isEnabled());
        self::assertFalse($context->isSingleBoard());
        self::assertNull($context->current($user));
        self::assertNull($context->require($user));
        self::assertTrue($context->owns($this->board(null), $user));
    }

    public function testResolvesTheTenantOncePerRequest(): void
    {
        $resolver = $this->createMock(TaskBoardTenantResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->with('42')->willReturn(new TaskBoardTenant('project-42', '42', 'Shop'));
        $context = new TaskBoardTenantContext($this->stack('42'), $resolver, true, true);
        $user    = new stdClass();

        self::assertTrue($context->isEnabled());
        self::assertTrue($context->isSingleBoard());
        self::assertSame('project-42', $context->current($user)?->key);
        self::assertSame('Shop', $context->require($user)?->label);
        self::assertTrue($context->owns($this->board('project-42'), $user));
        self::assertFalse($context->owns($this->board('project-7'), $user));
        self::assertFalse($context->owns($this->board(null), $user));
    }

    public function testARefusedSlugIsNotFound(): void
    {
        $resolver = $this->createMock(TaskBoardTenantResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(null);
        $context = new TaskBoardTenantContext($this->stack('42'), $resolver, true);
        $user    = new stdClass();

        self::assertNull($context->current($user));
        self::assertNull($context->current($user));
        self::assertFalse($context->owns($this->board('42'), $user));
        $this->expectException(NotFoundHttpException::class);
        $context->require($user);
    }

    public function testNoTenantOutsideATenantUrlOrRequest(): void
    {
        $user = new stdClass();

        self::assertNull((new TaskBoardTenantContext($this->stack(null), new SlugTaskBoardTenantResolver(), true))->current($user));
        self::assertNull((new TaskBoardTenantContext(new RequestStack(), new SlugTaskBoardTenantResolver(), true))->current($user));
    }

    public function testSlugResolverUsesTheSlugAsTheTenant(): void
    {
        $tenant = (new SlugTaskBoardTenantResolver())->resolve('acme', new stdClass());

        self::assertSame(['acme', 'acme', 'acme'], [$tenant->key, $tenant->slug, $tenant->label]);
    }

    private function stack(?string $slug): RequestStack
    {
        $request = new Request();
        if ($slug !== null) {
            $request->attributes->set('tenantSlug', $slug);
        }
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function board(?string $tenantKey): TaskBoard
    {
        return (new TaskBoard('Board', 'board', new stdClass()))->setTenantKey($tenantKey);
    }
}
