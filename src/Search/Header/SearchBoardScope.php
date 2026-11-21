<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\BoardListQueryEvent;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\BoardViewReach;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Nowo\TaskBoardBundle\User\TaskBoardUsers;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SortDirection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_chunk;
use function array_intersect;
use function array_map;
use function array_merge;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Since 2.25.0 (header search): the boards a viewer may open, within the scope the host allows.
 *
 * 1. {@see SearchScopeEvent} (TaskBoardEvents::SEARCH_SCOPE) lets the host narrow the tenants and boards, or deny.
 * 2. One query reads the active (not archived) boards of that scope — tenants, boards, exclusions and, for the board
 *    group, the words of the name, all in SQL — at most `search_kit.max_boards` of them, the most recently changed first.
 * 3. PHP keeps the rows whose keys match exactly (a collation may fold `Acme` to `acme`), with tenancy each board's
 *    tenant must resolve for the viewer (once per tenant), and each board passes canViewBoard() through
 *    {@see TaskBoardResourceAccessChecker::boardViewDecider()} (BOARD_ACCESS_CHECK, with no query per board).
 *
 * Any refusal is an empty set: no exception, no count. Stateless (FrankenPHP worker mode).
 */
final readonly class SearchBoardScope
{
    /** The scope prefix of one board (`board:<board id>`). */
    public const SCOPE_BOARD = 'board';

    /** The scope prefix of one tenant (`tenant:<tenant key>`). */
    public const SCOPE_TENANT = 'tenant';

    /**
     * Since 2.25.1 (audit VL2-1): the most team ids of the viewer the default rule puts in SQL (in lists of
     * {@see ExactKey::CHUNK}). When the rule filters in SQL (no BOARD_ACCESS_CHECK listener), the boards of further teams are
     * left out and the search is partial.
     */
    public const MAX_TEAMS = 10 * ExactKey::CHUNK;

    /** Upper bound of `search_kit.max_boards` (one `IN (...)` list of {@see ExactKey::CHUNK} ids). */
    public const MAX_BOARDS = ExactKey::CHUNK;

    /**
     * @param int $maxBoards `search_kit.max_boards`: the most visible boards one search keeps
     * @param int $maxScannedBoards `search_kit.max_scanned_boards`: the most boards one search reads to find them
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?TaskBoardTenantContext $tenantContext = null,
        private LoggerInterface $logger = new NullLogger(),
        private int $maxBoards = 200,
        private ?SearchTenants $tenants = null,
        private ?TaskBoardUsers $users = null,
        private int $maxScannedBoards = 1000,
    ) {
    }

    /**
     * The boards the viewer may open for a group.
     *
     * @param string $group {@see SearchScopeEvent::GROUP_TASKS} or {@see SearchScopeEvent::GROUP_BOARDS}
     * @param list<string> $nameWords For the board group, lower-case words that must all be in the board name (escaped LIKE)
     */
    public function boards(SearchViewer $viewer, string $group, array $nameWords = []): SearchableBoards
    {
        $user      = $viewer->user;
        $tenancy   = $this->tenantContext?->isEnabled() === true;
        $requested = null;
        if ($tenancy) {
            // A tenant page whose slug does not resolve for the viewer is refused, never read as "every tenant".
            $requested = $this->tenantContext->current($user)?->key;
            if ($requested === null && $this->tenantContext->isTenantRequest()) {
                return new SearchableBoards([]);
            }
        }

        $scope = new SearchScopeEvent($user, $group, $viewer->scope, $requested);
        // The bundle's own scopes (client input: they only narrow, and every board found is still checked).
        $native = self::nativeScope($viewer->scope);
        if ($native !== null && $native[0] === self::SCOPE_BOARD) {
            $scope->onlyBoards([$native[1]]);
        }
        $this->eventDispatcher?->dispatch($scope, TaskBoardEvents::SEARCH_SCOPE);
        if ($scope->isDenied() || ($viewer->scope !== null && $native === null && !$scope->isScopeAccepted())) {
            return new SearchableBoards([]);
        }

        $tenants = $scope->getOnlyTenants() ?? ($tenancy ? ($requested !== null ? [$requested] : []) : null);
        // Audit VL-2: `tenant:<key>` narrows the tenants the search may read (the listeners' or the request's), never widens them.
        if ($native !== null && $native[0] === self::SCOPE_TENANT) {
            $tenants = $tenants === null ? [$native[1]] : array_values(array_intersect($tenants, [$native[1]]));
        }
        $boards = $scope->getOnlyBoards();
        if ($tenants === [] || $boards === []) {
            return new SearchableBoards([]);
        }
        $excludedTenants = $scope->getExcludedTenants();
        $excludedBoards  = $scope->getExcludedBoards();
        // An exclusion list too long for one IN (...) list cannot be applied in SQL: fail closed rather than widen.
        if (count($excludedTenants) > ExactKey::CHUNK || count($excludedBoards) > ExactKey::CHUNK) {
            $this->logger->warning('TaskBoard header search scope refused: too many exclusions', ['bundle' => 'nowo_task_board', 'excluded_tenants' => count($excludedTenants), 'excluded_boards' => count($excludedBoards)]);

            return new SearchableBoards([]);
        }
        if ($tenants !== null) {
            $tenants = array_values(array_filter($tenants, static fn (string $key): bool => ExactKey::isUsable($key)));
            $tenants = $this->bounded($tenants, 'tenants');
            if ($tenants === []) {
                return new SearchableBoards([]);
            }
        }
        if ($boards !== null) {
            $boards = $this->bounded($boards, 'boards');
        }
        // Audit Q3: a BOARD_LIST_QUERY override (the boards the host lists on the index) bounds the search as well.
        $listed = $this->listedBoards($user, $tenants);
        if ($listed === false) {
            return new SearchableBoards([]);
        }

        $reach = $this->accessChecker->defaultBoardReach($user, $viewer->manageRoles);
        // Audit VL2-1: a team list past MAX_TEAMS cannot be applied whole when the default rule filters in SQL.
        $teamsCut = !$reach->all && !$reach->listenersMayGrant && count($reach->teamIds) > self::MAX_TEAMS;
        if ($teamsCut) {
            $this->logger->warning('TaskBoard header search team list cut', ['bundle' => 'nowo_task_board', 'teams' => count($reach->teamIds), 'kept' => self::MAX_TEAMS]);
        }
        $viewable = $this->accessChecker->boardViewDecider($user, $viewer->manageRoles, $reach);
        $limit    = max(1, min($this->maxBoards, self::MAX_BOARDS));
        $scanned  = 0;
        $bound    = max($limit, $this->maxScannedBoards);
        $more     = false;
        $found    = [];
        // Audit VL-1: boards are read in batches (newest first, the default-visible ones first when a listener may grant
        // more) until `max_boards` visible ones are kept or `max_scanned_boards` are read, so boards the viewer cannot
        // open never push out the ones they can.
        do {
            $batch = min($limit, $bound - $scanned);
            /** @var list<TaskBoard> $rows */
            $rows = $this->query($tenants, $boards, $excludedTenants, $excludedBoards, $nameWords, $tenancy, $listed, $user, $reach)
                ->setFirstResult($scanned)->setMaxResults($batch + 1)->getQuery()->getResult();
            $more = count($rows) > $batch;
            $rows = array_slice($rows, 0, $batch);
            $scanned += count($rows);
            foreach ($rows as $index => $board) {
                // Only a folding collation (MySQL / MariaDB `*_ci`) returns such a row; inScope() itself is unit-tested.
                if (!self::inScope($board, $tenants, $boards, $excludedTenants, $excludedBoards) || !self::listed($board, $listed)) {
                    continue; // @codeCoverageIgnore
                }
                $tenant = null;
                if ($tenancy) {
                    $tenant = $this->tenantOf((string) $board->getTenantKey(), $user);
                    if (!$tenant instanceof TaskBoardTenant) {
                        continue;
                    }
                }
                if ($viewable($board)) {
                    $found[$board->getId()] = new SearchableBoard($board, $tenant);
                    if (count($found) >= $limit) {
                        // Full: unread rows of this batch, or of the next, may hold more visible boards.
                        $more = $more || $index < count($rows) - 1;
                        break;
                    }
                }
            }
        } while ($more && count($found) < $limit && $scanned < $bound);
        if ($more) {
            $this->logger->warning('TaskBoard header search scope capped', ['bundle' => 'nowo_task_board', 'max_boards' => $limit, 'max_scanned_boards' => $bound, 'scanned' => $scanned]);
        }

        return new SearchableBoards($found, $more || $teamsCut);
    }

    /**
     * The BOARD_LIST_QUERY overrides of the searched tenants (one event per tenant, or one without tenancy): tenant key
     * ('' without a tenant) => the board ids the host lists there. False when an override lists no board for the only
     * scope (nothing to search); an empty array when no listener overrides.
     *
     * @param list<string>|null $tenants
     *
     * @return array<string, list<string>>|false
     */
    private function listedBoards(object $user, ?array $tenants): array|false
    {
        if ($this->eventDispatcher === null) {
            return [];
        }
        $listed = [];
        foreach ($tenants ?? [null] as $tenantKey) {
            $event = new BoardListQueryEvent($user, $tenantKey);
            $this->eventDispatcher->dispatch($event, TaskBoardEvents::BOARD_LIST_QUERY);
            $override = $event->getOverrideList();
            if ($override !== null) {
                $listed[(string) $tenantKey] = array_values(array_map(static fn (TaskBoard $board): string => $board->getId(), $override));
            }
        }
        if ($listed !== [] && count($listed) === count($tenants ?? [null]) && array_merge(...array_values($listed)) === []) {
            return false;
        }

        return $listed;
    }

    /**
     * Whether a board survives the BOARD_LIST_QUERY overrides (exact ids).
     *
     * @param array<string, list<string>> $listed
     */
    private static function listed(TaskBoard $board, array $listed): bool
    {
        $ids = $listed[''] ?? $listed[(string) $board->getTenantKey()] ?? null;

        return $ids === null || in_array($board->getId(), $ids, true);
    }

    /** The tenant of a key for the viewer, once per key and request ({@see SearchTenants}). */
    private function tenantOf(string $key, object $user): ?TaskBoardTenant
    {
        return $this->tenants instanceof SearchTenants ? $this->tenants->tenantOfKey($key, $user) : $this->tenantContext?->tenantOfKey($key, $user);
    }

    /**
     * @param list<string>|null $tenants
     * @param list<string>|null $boards
     * @param list<string> $excludedTenants
     * @param list<string> $excludedBoards
     * @param list<string> $nameWords
     * @param array<string, list<string>> $listed
     */
    private function query(?array $tenants, ?array $boards, array $excludedTenants, array $excludedBoards, array $nameWords, bool $tenancy, array $listed, object $user, BoardViewReach $reach): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL');
        if ($tenancy) {
            $qb->andWhere('b.tenantKey IS NOT NULL');
        }
        if ($tenants !== null) {
            $qb->andWhere('b.tenantKey IN (:tenants)')->setParameter('tenants', $tenants);
        }
        if ($excludedTenants !== []) {
            $qb->andWhere('(b.tenantKey IS NULL OR b.tenantKey NOT IN (:excludedTenants))')->setParameter('excludedTenants', $excludedTenants);
        }
        if ($boards !== null) {
            $qb->andWhere('b.id IN (:boards)')->setParameter('boards', $boards);
        }
        if ($excludedBoards !== []) {
            $qb->andWhere('b.id NOT IN (:excludedBoards)')->setParameter('excludedBoards', $excludedBoards);
        }
        $index = 0;
        foreach ($listed as $tenantKey => $ids) {
            $only = $ids === [] ? '1 = 0' : "b.id IN (:listedBoards{$index})";
            if ($ids !== []) {
                $qb->setParameter('listedBoards' . $index, $ids);
            }
            if ((string) $tenantKey === '') {
                // Without tenancy the host's list is the whole index.
                $qb->andWhere($only);
            } else {
                $qb->andWhere("(b.tenantKey <> :listedTenant{$index} OR {$only})")->setParameter('listedTenant' . $index, (string) $tenantKey);
            }
            ++$index;
        }
        foreach ($nameWords as $index => $word) {
            $qb->andWhere("LOWER(b.name) LIKE :name{$index} ESCAPE '!'")->setParameter('name' . $index, SearchText::contains($word));
        }
        // Audit VL-1 (b): the default rule (creator, the viewer's teams) filters in SQL when no listener may grant more,
        // else it only puts those boards first.
        $default = $reach->all ? null : $this->defaultCondition($qb, $user, $reach);
        if ($default !== null && !$reach->listenersMayGrant) {
            $qb->andWhere($default);
        } elseif ($default !== null) {
            $qb->addSelect("CASE WHEN {$default} THEN 0 ELSE 1 END AS HIDDEN defaultReach")->orderBy('defaultReach', SortDirection::Ascending);
        }

        return $qb->addOrderBy('b.updatedAt', SortDirection::Descending)->addOrderBy('b.id', SortDirection::Ascending);
    }

    /** The DQL condition of the default rule (creator, or a team of the viewer); "1 = 0" when neither applies. */
    private function defaultCondition(QueryBuilder $qb, object $user, BoardViewReach $reach): string
    {
        $parts   = [];
        $creator = $this->users instanceof TaskBoardUsers ? $this->users->parameter($user) : (ActorId::of($user) !== null ? $user : null);
        if ($creator !== null) {
            $parts[] = ($this->users instanceof TaskBoardUsers ? $this->users->identity('b.creator') : 'IDENTITY(b.creator)') . ' = :searchViewer';
            $qb->setParameter('searchViewer', $creator);
        }
        // Audit VL2-1: every team, in IN lists of at most ExactKey::CHUNK ids each (one bound parameter per list), up to
        // MAX_TEAMS; boards() reports a search past that cap as partial.
        foreach (array_chunk(array_slice($reach->teamIds, 0, self::MAX_TEAMS), ExactKey::CHUNK) as $index => $teamIds) {
            $parts[] = "IDENTITY(b.team) IN (:searchTeams{$index})";
            $qb->setParameter('searchTeams' . $index, $teamIds);
        }

        return $parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * The bundle's own scope (`board:<id>` or `tenant:<key>`) as [prefix, value], or null for any other (or no) scope.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function nativeScope(?string $scope): ?array
    {
        if ($scope === null) {
            return null;
        }
        foreach ([self::SCOPE_BOARD, self::SCOPE_TENANT] as $prefix) {
            $value = str_starts_with($scope, $prefix . ':') ? substr($scope, strlen($prefix) + 1) : '';
            if ($value !== '' && ExactKey::isUsable($value)) {
                return [$prefix, $value];
            }
        }

        return null;
    }

    /**
     * Exact checks of what SQL matched (a collation may fold keys and ids): whether the board is in the scope, byte for byte.
     *
     * @internal
     *
     * @param list<string>|null $tenants
     * @param list<string>|null $boards
     * @param list<string> $excludedTenants
     * @param list<string> $excludedBoards
     */
    public static function inScope(TaskBoard $board, ?array $tenants, ?array $boards, array $excludedTenants, array $excludedBoards): bool
    {
        $key = $board->getTenantKey();
        if ($tenants !== null && !ExactKey::oneOf($key, $tenants)) {
            return false;
        }
        if ($key !== null && in_array($key, $excludedTenants, true)) {
            return false;
        }
        $id = $board->getId();

        return ($boards === null || in_array($id, $boards, true)) && !in_array($id, $excludedBoards, true);
    }

    /**
     * An allow list longer than one IN (...) list keeps its first entries: it only narrows further.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function bounded(array $values, string $what): array
    {
        if (count($values) <= ExactKey::CHUNK) {
            return $values;
        }
        $this->logger->warning('TaskBoard header search scope list cut', ['bundle' => 'nowo_task_board', 'list' => $what, 'entries' => count($values), 'kept' => ExactKey::CHUNK]);

        return array_slice($values, 0, ExactKey::CHUNK);
    }
}
