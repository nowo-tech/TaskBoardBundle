<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function json_decode;
use function max;
use function mb_strtolower;
use function min;
use function strcmp;
use function usort;

/**
 * Since 2.13.0 (Beacon D-20261112-01): {@see MultiBoardTaskListInterface} over the bundle's tables. Each call asks the
 * provider once, loads the scope's boards in one query, keeps those `canViewBoard()` allows (defense in depth) and then
 * reads the tasks of exactly those boards. Stateless: every value lives in the call (FrankenPHP worker mode).
 */
final readonly class MultiBoardTaskList implements MultiBoardTaskListInterface
{
    /** Tasks per query of an export or a label scan. */
    public const BATCH = 500;

    /**
     * @param class-string $userClass `user_class`
     * @param int $maxBoards `multi_board.max_boards`: boards of a scope beyond it are left out (logged)
     * @param int $scanMaxRows `multi_board.scan_max_rows`: the most tasks a label filter reads
     * @param int $exportMaxRows `multi_board.export.max_rows`
     */
    public function __construct(
        private MultiBoardScopeProviderInterface $scopeProvider,
        private TaskBoardResourceAccessChecker $resourceAccessChecker,
        private DoctrineMultiBoardTaskQuery $query,
        private EntityManagerInterface $entityManager,
        private string $userClass,
        private int $maxBoards = 500,
        private int $scanMaxRows = 10000,
        private int $exportMaxRows = 5000,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
        // Since audit NI-3: with tenancy, each board's tenant must resolve for the viewer, as the Task API requires.
        private ?TaskBoardTenantContext $tenantContext = null,
        // Since audit NI-4: the signed-in user, whose manage roles apply only when the viewer is that user.
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function boards(MultiBoardViewer $viewer): array
    {
        return array_values($this->resolve($viewer));
    }

    public function page(MultiBoardViewer $viewer, MultiBoardTaskFilter $filter, int $page, int $perPage): MultiBoardTaskPage
    {
        $perPage = max(1, $perPage);
        $page    = max(1, $page);
        $boards  = $this->resolve($viewer);
        if ($boards === []) {
            return new MultiBoardTaskPage([], 0, 1, $perPage, [], [], []);
        }

        [$filter, $ids, $assigneeIds] = $this->narrow($filter, $boards);
        $now                          = $this->now();
        $scopeIds                     = array_keys($boards);
        $assignees                    = $this->people($assigneeIds);
        $columns                      = $this->query->columnNames($scopeIds);

        if ($filter->label === null) {
            $total = $this->query->count($ids, $filter, $now);
            $page  = min($page, max(1, (int) ceil($total / $perPage)));
            $raw   = $this->query->rows($ids, $filter, $now, ($page - 1) * $perPage, $perPage);

            return new MultiBoardTaskPage($this->toRows($raw, $boards, $now), $total, $page, $perPage, array_values($boards), $assignees, $columns);
        }

        // A label filter: tags are a JSON column no portable query can search, so the matching tasks are read in batches
        // (at most scan_max_rows) and compared here; the requested page and the last page are kept on the way.
        $window = [];
        $tail   = [];
        $total  = 0;
        $start  = ($page - 1) * $perPage;
        foreach ($this->scan($ids, $filter, $now, true) as $row) {
            if ($start <= $total && $total < $start + $perPage) {
                $window[] = $row;
            }
            $tail[] = $row;
            if (count($tail) > $perPage) {
                $tail = array_slice($tail, 1);
            }
            ++$total;
        }
        $last = max(1, (int) ceil($total / $perPage));
        if ($page > $last) {
            $page   = $last;
            $window = $total === 0 ? [] : array_slice($tail, -(($total - 1) % $perPage + 1));
        }

        return new MultiBoardTaskPage($this->toRows($window, $boards, $now), $total, $page, $perPage, array_values($boards), $assignees, $columns, $this->query->count($ids, $filter, $now) > $this->scanMaxRows);
    }

    public function workload(MultiBoardViewer $viewer, MultiBoardTaskFilter $filter): MultiBoardWorkload
    {
        $boards = $this->resolve($viewer);
        if ($boards === []) {
            return new MultiBoardWorkload([], [], [], []);
        }

        [$filter, $ids, $assigneeIds] = $this->narrow($filter, $boards);
        $now                          = $this->now();
        $scopeIds                     = array_keys($boards);
        $partial                      = false;
        /** @var array<string, array{open: int, overdue: int, done: int, estimate: int, logged: int}> $totals keyed by user id, '' = unassigned */
        $totals = [];
        if ($filter->label === null) {
            foreach ($this->query->workload($ids, $filter, $now) as $row) {
                $totals[$row['userId'] === null ? '' : (string) $row['userId']] = [
                    'open'     => $this->int($row['openCount']),
                    'overdue'  => $this->int($row['overdueCount']),
                    'done'     => $this->int($row['doneCount']),
                    'estimate' => $this->int($row['estimate']),
                    'logged'   => intdiv($this->int($row['logged']), 60),
                ];
            }
        } else {
            $batch = [];
            foreach ($this->scan($ids, $filter, $now, false) as $row) {
                $batch[] = $row;
                if (count($batch) === self::BATCH) {
                    $this->addWorkload($totals, $batch, $now);
                    $batch = [];
                }
            }
            $this->addWorkload($totals, $batch, $now);
            $partial = $this->query->count($ids, new MultiBoardTaskFilter($filter->boardId, $filter->assigneeId, $filter->column, MultiBoardTaskFilter::STATUS_ALL, $filter->priority, $filter->dueFrom, $filter->dueTo, $filter->overdue, $filter->label, $filter->search), $now) > $this->scanMaxRows;
        }

        $labels = $this->query->people(array_values(array_filter(array_map(strval(...), array_keys($totals)), static fn (string $id): bool => $id !== '')));
        $rows   = [];
        foreach ($totals as $userId => $total) {
            $userId = (string) $userId;
            $rows[] = new MultiBoardWorkloadRow(
                $userId === '' ? null : new MultiBoardPerson($userId, $labels[$userId] ?? $userId),
                $total['open'],
                $total['overdue'],
                $total['done'],
                $total['estimate'],
                $total['logged'],
            );
        }
        usort($rows, static function (MultiBoardWorkloadRow $a, MultiBoardWorkloadRow $b): int {
            if ((!$a->person instanceof MultiBoardPerson) !== (!$b->person instanceof MultiBoardPerson)) {
                return $a->person instanceof MultiBoardPerson ? -1 : 1;
            }

            return [$b->open, $b->overdue, mb_strtolower($a->person->label ?? '')] <=> [$a->open, $a->overdue, mb_strtolower($b->person->label ?? '')];
        });

        return new MultiBoardWorkload($rows, array_values($boards), $this->people($assigneeIds), $this->query->columnNames($scopeIds), $partial);
    }

    public function export(MultiBoardViewer $viewer, MultiBoardTaskFilter $filter, ?int $maxRows = null): ?MultiBoardExportRows
    {
        $boards = $this->resolve($viewer);
        if ($boards === []) {
            return null;
        }

        [$filter, $ids] = $this->narrow($filter, $boards);
        $now            = $this->now();
        $maxRows        = max(1, $maxRows ?? $this->exportMaxRows);
        $matching       = $this->query->count($ids, $filter, $now);
        // Audit NI-2: with a label filter only the first scan_max_rows tasks are compared, so the export may miss matches.
        $partial = $filter->label !== null && $matching > $this->scanMaxRows;
        if ($filter->label !== null) {
            // A first pass counts the matches, so the truncation header is right before the body is sent (audit NI-7:
            // a label export reads up to scan_max_rows tasks twice).
            $matching = 0;
            foreach ($this->scan($ids, $filter, $now, true) as $ignored) {
                ++$matching;
            }
        }

        return new MultiBoardExportRows(function () use ($ids, $filter, $now, $boards, $maxRows): Generator {
            $written = 0;
            $batch   = [];
            $source  = $filter->label === null ? $this->plain($ids, $filter, $now, $maxRows) : $this->scan($ids, $filter, $now, true);
            foreach ($source as $row) {
                $batch[] = $row;
                ++$written;
                if (count($batch) === self::BATCH || $written === $maxRows) {
                    yield from $this->toRows($batch, $boards, $now);
                    $batch = [];
                }
                if ($written === $maxRows) {
                    return;
                }
            }
            yield from $this->toRows($batch, $boards, $now);
        }, $matching, $maxRows, $partial);
    }

    /**
     * The scope's boards the viewer may read, keyed by id, in the provider's order.
     *
     * @return array<string, MultiBoardBoard>
     */
    private function resolve(MultiBoardViewer $viewer): array
    {
        $scope = $this->scopeProvider->readableBoards($viewer);
        if ($scope->isEmpty()) {
            return [];
        }
        $user = $this->user($viewer->userId);
        if ($user === null) {
            return [];
        }

        $entries = $scope->entries;
        if (count($entries) > $this->maxBoards) {
            $this->logger->warning('TaskBoard multi-board scope capped', ['bundle' => 'nowo_task_board', 'boards' => count($entries), 'max_boards' => $this->maxBoards]);
            $entries = array_slice($entries, 0, $this->maxBoards);
        }

        $found = [];
        foreach ($this->query->boards(array_map(static fn (MultiBoardScopeEntry $entry): string => $entry->boardId, $entries)) as $board) {
            $found[$board->getId()] = $board;
        }

        $manageRoles = $this->isSignedIn($viewer->userId);
        $boards      = [];
        foreach ($entries as $entry) {
            $board = $found[$entry->boardId] ?? null;
            // Defense in depth: the bundle's own read check (creator, team, manage roles of the signed-in viewer only,
            // BOARD_ACCESS_CHECK listeners) and, with tenancy, the board's tenant.
            if ($board instanceof TaskBoard && $board->getId() === $entry->boardId && $this->ownsTenant($user, $board, $entry) && $this->resourceAccessChecker->canViewBoard($user, $board, $manageRoles)) {
                $boards[$entry->boardId] = new MultiBoardBoard($entry->boardId, $entry->label ?? $board->getName(), $board->getName(), $entry->tenantSlug);
            }
        }

        return $boards;
    }

    /**
     * Without tenancy, always. With tenancy (audit NI-3), the board needs a tenant the resolver gives this user (as
     * `TaskApiGuard`), and an entry's tenant slug must be that tenant's.
     */
    private function ownsTenant(object $user, TaskBoard $board, MultiBoardScopeEntry $entry): bool
    {
        if ($this->tenantContext?->isEnabled() !== true) {
            return true;
        }
        $key    = $board->getTenantKey();
        $tenant = $key !== null ? $this->tenantContext->tenantOfKey($key, $user) : null;

        return $tenant instanceof TaskBoardTenant && ($entry->tenantSlug === null || $entry->tenantSlug === $tenant->slug);
    }

    /** Whether the viewer is the signed-in user (audit NI-4: only then do the token's manage roles apply). */
    private function isSignedIn(string $userId): bool
    {
        $signedIn = $this->tokenStorage?->getToken()?->getUser();
        if (!is_object($signedIn) || !method_exists($signedIn, 'getId')) {
            return false;
        }
        $id = $signedIn->getId();

        return is_scalar($id) && (string) $id === $userId;
    }

    /** The user of the viewer's id; null for an id that names nobody (or cannot, e.g. text for an integer id). */
    private function user(string $userId): ?object
    {
        $metadata = $this->entityManager->getClassMetadata($this->userClass);
        $type     = $metadata->getTypeOfField($metadata->getSingleIdentifierFieldName());
        if (in_array($type, ['integer', 'smallint', 'bigint'], true) && !ctype_digit($userId)) {
            return null;
        }

        $user = $this->entityManager->find($this->userClass, $userId);
        if (!is_object($user)) {
            return null;
        }
        $id = $this->entityManager->getUnitOfWork()->getSingleIdentifierValue($user);

        // Exact comparison (BUNDLES_FIX_RULES "Identifier columns"): a collation must not map one id to another user.
        return $id !== null && (string) $id === $userId ? $user : null;
    }

    /**
     * A board the viewer may not read is ignored, and so is an assignee who has no task on the readable boards (audit NI-1:
     * a crafted value, e.g. text for an integer user id, never reaches a query). Returns the filter, the boards the query
     * reads and the assignee ids of the scope (the filter choices).
     *
     * @param array<string, MultiBoardBoard> $boards
     *
     * @return array{MultiBoardTaskFilter, list<string>, list<string>}
     */
    private function narrow(MultiBoardTaskFilter $filter, array $boards): array
    {
        $scopeIds  = array_map(strval(...), array_keys($boards));
        $assignees = $this->query->assigneesOf($scopeIds);
        if ($filter->assigneeId !== null && $filter->assigneeId !== MultiBoardTaskFilter::UNASSIGNED && !in_array($filter->assigneeId, $assignees, true)) {
            $filter = $filter->withoutAssignee();
        }
        if ($filter->boardId !== null && isset($boards[$filter->boardId])) {
            return [$filter, [$filter->boardId], $assignees];
        }

        return [$filter->boardId !== null ? $filter->withoutBoard() : $filter, $scopeIds, $assignees];
    }

    /**
     * Matching rows without a label filter, batch by batch, at most `$limit`.
     *
     * @param list<string> $ids
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function plain(array $ids, MultiBoardTaskFilter $filter, DateTimeImmutable $now, int $limit): Generator
    {
        for ($offset = 0; $offset < $limit; $offset += self::BATCH) {
            $batch = $this->query->rows($ids, $filter, $now, $offset, min(self::BATCH, $limit - $offset));
            yield from $batch;
            if (count($batch) < min(self::BATCH, $limit - $offset)) {
                return;
            }
        }
    }

    /**
     * Matching rows that carry the filter's label, from at most `scan_max_rows` rows read in batches.
     *
     * @param list<string> $ids
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function scan(array $ids, MultiBoardTaskFilter $filter, DateTimeImmutable $now, bool $withStatus): Generator
    {
        $wanted = mb_strtolower((string) $filter->label);
        $query  = $withStatus ? $filter : new MultiBoardTaskFilter($filter->boardId, $filter->assigneeId, $filter->column, MultiBoardTaskFilter::STATUS_ALL, $filter->priority, $filter->dueFrom, $filter->dueTo, $filter->overdue, null, $filter->search, $filter->sort, $filter->direction);
        foreach ($this->plain($ids, $query, $now, $this->scanMaxRows) as $row) {
            if (in_array($wanted, array_map(mb_strtolower(...), $this->labels($row['tags'] ?? null)), true)) {
                yield $row;
            }
        }
    }

    /**
     * @param array<string, array{open: int, overdue: int, done: int, estimate: int, logged: int}> $totals
     * @param list<array<string, mixed>> $batch
     */
    private function addWorkload(array &$totals, array $batch, DateTimeImmutable $now): void
    {
        if ($batch === []) {
            return;
        }
        $assignees = $this->query->assigneeIds(array_map(static fn (array $row): string => (string) $row['taskId'], $batch));
        foreach ($batch as $row) {
            $completed = $this->date($row['completedAt'] ?? null) instanceof DateTimeImmutable;
            $due       = $this->date($row['dueAt'] ?? null);
            foreach ($assignees[(string) $row['taskId']] ?? [''] as $userId) {
                $totals[$userId] ??= ['open' => 0, 'overdue' => 0, 'done' => 0, 'estimate' => 0, 'logged' => 0];
                $totals[$userId]['open'] += $completed ? 0 : 1;
                $totals[$userId]['overdue'] += !$completed && $due instanceof DateTimeImmutable && $due < $now ? 1 : 0;
                $totals[$userId]['done'] += $completed ? 1 : 0;
                $totals[$userId]['estimate'] += $completed ? 0 : $this->int($row['estimatedMinutes'] ?? null);
                $totals[$userId]['logged'] += intdiv($this->int($row['totalTimeSeconds'] ?? null), 60);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $raw
     * @param array<string, MultiBoardBoard> $boards
     *
     * @return list<MultiBoardTaskRow>
     */
    private function toRows(array $raw, array $boards, DateTimeImmutable $now): array
    {
        if ($raw === []) {
            return [];
        }
        $assignees = $this->query->assigneeIds(array_map(static fn (array $row): string => (string) $row['taskId'], $raw));
        $userIds   = [];
        foreach ($assignees as $ids) {
            foreach ($ids as $id) {
                $userIds[$id] = true;
            }
        }
        $labels = $this->query->people(array_map(strval(...), array_keys($userIds)));

        $rows = [];
        foreach ($raw as $row) {
            $taskId = (string) $row['taskId'];
            // The query reads only these boards.
            $board     = $boards[(string) $row['boardId']];
            $due       = $this->date($row['dueAt'] ?? null);
            $completed = $this->date($row['completedAt'] ?? null);
            $priority  = $row['priority'] ?? null;
            $rows[]    = new MultiBoardTaskRow(
                taskId: $taskId,
                number: is_numeric($row['number'] ?? null) ? (int) $row['number'] : null,
                title: (string) $row['title'],
                board: $board,
                columnName: is_string($row['columnName'] ?? null) ? $row['columnName'] : null,
                priority: $priority instanceof TaskPriority ? $priority : (TaskPriority::tryFrom(is_string($priority) ? $priority : '') ?? TaskPriority::Normal),
                startAt: $this->date($row['startAt'] ?? null),
                dueAt: $due,
                completedAt: $completed,
                estimatedMinutes: is_numeric($row['estimatedMinutes'] ?? null) ? (int) $row['estimatedMinutes'] : null,
                loggedMinutes: intdiv($this->int($row['totalTimeSeconds'] ?? null), 60),
                labels: $this->labels($row['tags'] ?? null),
                assignees: array_map(static fn (string $id): MultiBoardPerson => new MultiBoardPerson($id, $labels[$id] ?? $id), $assignees[$taskId] ?? []),
                parentId: isset($row['parentId']) && $row['parentId'] !== '' ? (string) $row['parentId'] : null,
                overdue: !$completed instanceof DateTimeImmutable && $due instanceof DateTimeImmutable && $due < $now,
            );
        }

        return $rows;
    }

    /**
     * @param list<string> $userIds
     *
     * @return list<MultiBoardPerson>
     */
    private function people(array $userIds): array
    {
        $people = [];
        foreach ($this->query->people($userIds) as $id => $label) {
            $people[] = new MultiBoardPerson((string) $id, $label);
        }
        usort($people, static fn (MultiBoardPerson $a, MultiBoardPerson $b): int => strcmp(mb_strtolower($a->label), mb_strtolower($b->label)) ?: strcmp($a->id, $b->id));

        return $people;
    }

    /**
     * @return list<string>
     */
    private function labels(mixed $tags): array
    {
        // The JSON type decodes the column; a driver that hands back the text is decoded here.
        $tags   = is_string($tags) ? json_decode($tags, true) : $tags;
        $labels = [];
        foreach (is_array($tags) ? $tags : [] as $tag) {
            if (is_string($tag) && $tag !== '') {
                $labels[] = $tag;
            }
        }

        return $labels;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : (is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null);
    }

    private function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
