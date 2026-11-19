<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_key_exists;
use function is_array;
use function spl_object_id;

/**
 * Since 2.25.0 (header search, audit VL-3): {@see TaskBoardTenantContext::tenantOfKey()} once per tenant key and viewer
 * within one request, so a search asks the host's tenant resolver once per distinct tenant (the scope and the rows'
 * URLs share it), not once per board or row. The answers live on the current request (an attribute): nothing is kept
 * between requests (FrankenPHP worker mode). Outside a request every call asks the resolver.
 */
final readonly class SearchTenants
{
    /** Request attribute holding the answers of this request. */
    public const ATTRIBUTE = '_nowo_task_board_search_tenants';

    public function __construct(
        private ?TaskBoardTenantContext $tenantContext = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    /** The tenant of `$key` for `$user` (null: none, or tenancy off). */
    public function tenantOfKey(string $key, object $user): ?TaskBoardTenant
    {
        if (!$this->tenantContext instanceof TaskBoardTenantContext) {
            return null;
        }
        $request = $this->requestStack?->getCurrentRequest();
        if (!$request instanceof Request) {
            return $this->tenantContext->tenantOfKey($key, $user);
        }
        $known = $request->attributes->get(self::ATTRIBUTE);
        $known = is_array($known) ? $known : [];
        $slot  = (ActorId::of($user) ?? '#' . spl_object_id($user)) . "\0" . $key;
        if (!array_key_exists($slot, $known)) {
            $known[$slot] = $this->tenantContext->tenantOfKey($key, $user);
            // @igor-ignore - Request attribute: request-scoped memo, not worker state.
            $request->attributes->set(self::ATTRIBUTE, $known);
        }
        $tenant = $known[$slot];

        return $tenant instanceof TaskBoardTenant ? $tenant : null;
    }
}
