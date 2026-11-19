<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ExactKey;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_key_exists;
use function array_slice;
use function count;
use function in_array;

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
    /** Upper bound of `search_kit.max_boards` (one `IN (...)` list of {@see ExactKey::CHUNK} ids). */
    public const MAX_BOARDS = ExactKey::CHUNK;

    /**
     * @param int $maxBoards `search_kit.max_boards`: the most boards one search reads
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $accessChecker,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?TaskBoardTenantContext $tenantContext = null,
        private LoggerInterface $logger = new NullLogger(),
        private int $maxBoards = 200,
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
        $this->eventDispatcher?->dispatch($scope, TaskBoardEvents::SEARCH_SCOPE);
        if ($scope->isDenied()) {
            return new SearchableBoards([]);
        }

        $tenants = $scope->getOnlyTenants() ?? ($tenancy ? ($requested !== null ? [$requested] : []) : null);
        $boards  = $scope->getOnlyBoards();
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

        $limit = max(1, min($this->maxBoards, self::MAX_BOARDS));
        $rows  = $this->query($tenants, $boards, $excludedTenants, $excludedBoards, $nameWords, $tenancy)->setMaxResults($limit + 1)->getQuery()->getResult();
        /** @var list<TaskBoard> $rows */
        $capped = count($rows) > $limit;
        if ($capped) {
            $this->logger->warning('TaskBoard header search scope capped', ['bundle' => 'nowo_task_board', 'max_boards' => $limit]);
            $rows = array_slice($rows, 0, $limit);
        }

        $viewable = $this->accessChecker->boardViewDecider($user, $viewer->manageRoles);
        $resolved = [];
        $found    = [];
        foreach ($rows as $board) {
            if (!$this->inScope($board, $tenants, $boards, $excludedTenants, $excludedBoards)) {
                continue;
            }
            $tenant = null;
            if ($tenancy) {
                $key = (string) $board->getTenantKey();
                if (!array_key_exists($key, $resolved)) {
                    $resolved[$key] = $this->tenantContext->tenantOfKey($key, $user);
                }
                $tenant = $resolved[$key];
                if (!$tenant instanceof TaskBoardTenant) {
                    continue;
                }
            }
            if ($viewable($board)) {
                $found[$board->getId()] = new SearchableBoard($board, $tenant);
            }
        }

        return new SearchableBoards($found, $capped);
    }

    /**
     * @param list<string>|null $tenants
     * @param list<string>|null $boards
     * @param list<string> $excludedTenants
     * @param list<string> $excludedBoards
     * @param list<string> $nameWords
     */
    private function query(?array $tenants, ?array $boards, array $excludedTenants, array $excludedBoards, array $nameWords, bool $tenancy): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL')
            ->orderBy('b.updatedAt', 'DESC')
            ->addOrderBy('b.id', 'ASC');
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
        foreach ($nameWords as $index => $word) {
            $qb->andWhere("LOWER(b.name) LIKE :name{$index} ESCAPE '!'")->setParameter('name' . $index, SearchText::contains($word));
        }

        return $qb;
    }

    /**
     * Exact checks of what SQL matched (a collation may fold keys and ids).
     *
     * @param list<string>|null $tenants
     * @param list<string>|null $boards
     * @param list<string> $excludedTenants
     * @param list<string> $excludedBoards
     */
    private function inScope(TaskBoard $board, ?array $tenants, ?array $boards, array $excludedTenants, array $excludedBoards): bool
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
