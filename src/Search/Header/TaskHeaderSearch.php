<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use SortDirection;

use function array_map;
use function count;
use function max;
use function min;

/**
 * Since 2.25.0 (header search): the tasks whose title or description holds every word of the query, on the boards the
 * viewer may open ({@see SearchBoardScope}).
 *
 * One query reads the matching tasks of those boards that are not archived (boards, archive and the text condition of
 * {@see TaskTextMatch} in SQL: title words, description words of `search_kit.description_min_length` characters or more,
 * FULLTEXT where enabled, and since 2.26.0 a task number, key or external id, ranked first), the most recently changed
 * first, at most `search_kit.max_candidates`; one more query loads the assignees of the shown rows. Each one then passes TASK_ACCESS_CHECK
 * ({@see TaskBoardResourceAccessChecker::taskViewDecider()}): a deny hides it. The rows are cut to the limit after
 * that check, and the total counts only the tasks that passed it. Stateless (FrankenPHP worker mode).
 */
final readonly class TaskHeaderSearch
{
    /** Upper bound of the rows of one group. */
    public const MAX_LIMIT = 50;

    /**
     * @param int $scanRows `search_kit.scan_rows`: the most matching tasks one search checks
     */
    public function __construct(
        private SearchBoardScope $scope,
        private EntityManagerInterface $entityManager,
        private TaskBoardResourceAccessChecker $accessChecker,
        private SearchHitMapper $mapper,
        private int $scanRows = 100,
        // Since 2.26.0: the text condition (words, description length, FULLTEXT, references) and the rows' extras.
        private TaskTextMatch $match = new TaskTextMatch(),
        private ?TaskSearchExtras $extras = null,
    ) {
    }

    public function search(SearchViewer $viewer, string $text, int $limit): SearchHits
    {
        $words = SearchText::words($text);
        if ($words === []) {
            return new SearchHits();
        }
        $boards = $this->scope->boards($viewer, SearchScopeEvent::GROUP_TASKS);
        if ($boards->isEmpty()) {
            // Audit VL-1 (c): a scan that stopped at its bound says so, even when it kept no board.
            return new SearchHits([], 0, $boards->capped);
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $scan  = max($limit, $this->scanRows);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('t', 'searchColumn')
            ->from(Task::class, 't')
            ->leftJoin('t.column', 'searchColumn')
            ->where('IDENTITY(t.board) IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('boards', $boards->ids())
            ->orderBy('t.updatedAt', SortDirection::Descending)
            ->addOrderBy('t.id', SortDirection::Ascending)
            ->setMaxResults($scan);
        $reference = $this->match->apply($qb, $text);
        /** @var list<Task> $tasks */
        $tasks = $qb->getQuery()->getResult();

        $visible = $this->accessChecker->taskViewDecider($viewer->user, static fn (TaskBoard $board): bool => $boards->has($board->getId()));
        $shown   = [];
        $total   = 0;
        foreach ($tasks as $task) {
            $board = $boards->boards[$task->getBoard()->getId()] ?? null;
            // Only tasks of the searched boards: a task whose board left the set (or a folded id) is never shown.
            if ($board === null || !$visible($task)) {
                continue;
            }
            ++$total;
            if (count($shown) < $limit) {
                $shown[] = [$task, $board->tenant];
            }
        }
        // Since 2.26.0: the assignees of the shown rows, in one query.
        $initials = $this->extras?->assigneeInitials(array_map(static fn (array $row): Task => $row[0], $shown)) ?? [];
        $hits     = array_map(fn (array $row): SearchHit => $this->mapper->task($row[0], $row[1], $words, $initials[$row[0]->getId()] ?? [], $reference), $shown);

        return new SearchHits($hits, $total, count($tasks) >= $scan || $boards->capped);
    }
}
