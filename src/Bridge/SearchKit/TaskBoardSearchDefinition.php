<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\SearchKit;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Routing\TenantPaths;
use Nowo\TaskBoardBundle\Search\Header\SearchBoardScope;
use Nowo\TaskBoardBundle\Search\Header\SearchHitMapper;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;
use Nowo\TaskBoardBundle\Search\Header\SearchViewers;
use Nowo\TaskBoardBundle\Security\TaskBoardAccessCheckerInterface;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function implode;
use function is_string;
use function max;
use function min;
use function preg_match;

/**
 * Since 2.25.0: what the SearchKit groups `tasks` and `boards` share (SearchKit >= 1.9, {@see EntitySearchDefinition}).
 *
 * - The viewer is the signed-in user whose identifier is the query's ({@see SearchViewers}); anyone else gets nothing.
 * - The "tenant" SearchKit sees is the tenant key of the current tenant page, or {@see self::ANY_TENANT} elsewhere (and
 *   without tenancy): the tenants actually searched are decided by `TaskBoardEvents::SEARCH_SCOPE` and the viewer's
 *   access, per board ({@see SearchBoardScope}). A tenant page whose slug does not resolve for the viewer has no tenant.
 * - The feature gate is `TaskBoardAccessCheckerInterface::canAccess()` (the manage UI's own check).
 * - The scope of the search box is applied by {@see SearchBoardScope} (`board:<id>`, `tenant:<key>`, or a host scope a
 *   SEARCH_SCOPE listener accepts), in SQL; any other scope finds nothing.
 *
 * Stateless (FrankenPHP worker mode): the viewer and the boards are resolved on every call.
 */
abstract class TaskBoardSearchDefinition extends EntitySearchDefinition
{
    /** The SearchKit tenant outside a tenant page: the scope is the viewer's boards the SEARCH_SCOPE event allows. */
    public const ANY_TENANT = '*';

    /** The shape of a task or board id (a lower-case UUID). */
    private const ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /**
     * @param array<string, array{name?: string}> $routes `routes`
     * @param int $maxCandidates `search_kit.max_candidates`
     */
    public function __construct(
        protected readonly SearchViewers $viewers,
        protected readonly SearchBoardScope $scope,
        protected readonly EntityManagerInterface $entityManager,
        protected readonly TaskBoardResourceAccessChecker $resourceAccess,
        protected readonly TaskBoardAccessCheckerInterface $access,
        protected readonly SearchHitMapper $mapper,
        protected readonly ?TranslatorInterface $translator = null,
        protected readonly ?TaskBoardTenantContext $tenantContext = null,
        protected readonly ?TenantPaths $tenantPaths = null,
        protected readonly array $routes = [],
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
    ) {
    }

    public function tenant(SearchQuery $query): int|string|null
    {
        $viewer = $this->viewer($query);
        if (!$viewer instanceof SearchViewer) {
            return null;
        }
        if ($this->tenantContext?->isEnabled() !== true) {
            return self::ANY_TENANT;
        }
        $tenant = $this->tenantContext->current($viewer->user);
        if (!$tenant instanceof TaskBoardTenant && $this->tenantContext->isTenantRequest()) {
            return null;
        }

        return $tenant instanceof TaskBoardTenant ? $tenant->key : self::ANY_TENANT;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        $viewer = $this->viewer($query);

        return $viewer instanceof SearchViewer && $this->access->canAccess($viewer->user);
    }

    /** The scope is applied by SearchBoardScope inside baseQuery() (in SQL); an unknown scope already left no board. */
    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return true;
    }

    public function maxCandidates(): int
    {
        return max(1, min($this->maxCandidates, 1000));
    }

    public function referenceId(SearchReference $reference): int|string|null
    {
        [$route, $parameter] = $this->referenceRoute();
        $name                = $this->routes[$route]['name'] ?? null;
        $id                  = $reference->routeParameters[$parameter] ?? null;
        $bundleRoute         = $this->tenantPaths?->bundleRoute($reference->route) ?? $reference->route;

        // Client input: the route must be the group's and the id a lower-case UUID, as the bundle generates them.
        return is_string($name) && $bundleRoute === $name && is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /**
     * The `routes` key and the id parameter of this group's rows (`['task', 'taskId']`, `['board', 'boardId']`).
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function referenceRoute(): array;

    /** The viewer of the query, or null (not the signed-in user). */
    protected function viewer(SearchQuery $query): ?SearchViewer
    {
        return $this->viewers->forIdentifier($query->userIdentifier, $query->scope);
    }

    /** The translated label of `task_board.search.group.<group>`. */
    protected function translated(string $key, string $fallback, SearchQuery $query): string
    {
        return $this->translator?->trans($key, [], TaskBoardBundle::TRANSLATION_DOMAIN, $query->locale) ?? $fallback;
    }

    /** The board's tenant for the viewer (its URL and label), or null without tenancy. */
    protected function tenantOf(TaskBoard $board, SearchViewer $viewer): ?TaskBoardTenant
    {
        $key = $board->getTenantKey();

        return $key !== null && $this->tenantContext?->isEnabled() === true ? $this->tenantContext->tenantOfKey($key, $viewer->user) : null;
    }

    /**
     * Parts joined as one subtitle.
     *
     * @param list<string|null> $parts
     */
    protected static function subtitle(array $parts): string
    {
        return implode(' — ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
