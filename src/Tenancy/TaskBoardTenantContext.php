<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function is_string;

/**
 * The tenant of the current request (`tenancy.enabled`): resolved once per request from its `{tenantSlug}` and kept
 * on the request (worker-safe), and whether a board belongs to it.
 */
final readonly class TaskBoardTenantContext
{
    /** Request attribute holding the resolved tenant (or false when the slug resolved to none). */
    public const ATTRIBUTE = '_nowo_task_board_tenant';

    /**
     * Creates the context.
     *
     * @param bool $enabled `tenancy.enabled`
     * @param bool $singleBoard `tenancy.single_board`: a tenant's index opens its only board
     */
    public function __construct(
        private RequestStack $requestStack,
        private TaskBoardTenantResolverInterface $resolver,
        private bool $enabled = false,
        private bool $singleBoard = false,
    ) {
    }

    /**
     * Whether the boards live under tenant URLs.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether a tenant's index opens its only board (created on the first visit).
     */
    public function isSingleBoard(): bool
    {
        return $this->singleBoard;
    }

    /**
     * The tenant of the current request for `$user`; null without tenancy, outside a tenant URL or for a slug the
     * resolver refuses.
     */
    public function current(object $user): ?TaskBoardTenant
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$this->enabled || !$request instanceof Request) {
            return null;
        }

        $known = $request->attributes->get(self::ATTRIBUTE);
        if ($known instanceof TaskBoardTenant) {
            return $known;
        }
        if ($known === false) {
            return null;
        }

        $slug   = $request->attributes->get('tenantSlug');
        $tenant = is_string($slug) && $slug !== '' ? $this->resolver->resolve($slug, $user) : null;
        // @igor-ignore - Request attribute: request-scoped memo, not worker state.
        $request->attributes->set(self::ATTRIBUTE, $tenant ?? false);

        return $tenant;
    }

    /**
     * The tenant of the current request; with tenancy, a request without one answers 404.
     *
     * @throws NotFoundHttpException When tenancy is on and the request names no tenant the user may open
     */
    public function require(object $user): ?TaskBoardTenant
    {
        if (!$this->enabled) {
            return null;
        }

        $tenant = $this->current($user);
        if (!$tenant instanceof TaskBoardTenant) {
            throw new NotFoundHttpException('Tenant not found.');
        }

        return $tenant;
    }

    /**
     * Whether `$board` may be served at the current request: always without tenancy, else only the boards of the
     * request's tenant.
     */
    public function owns(TaskBoard $board, object $user): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $tenant = $this->current($user);

        return $tenant instanceof TaskBoardTenant && $board->getTenantKey() === $tenant->key;
    }
}
