<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;

use function count;
use function max;
use function min;

/**
 * Since 2.25.0 (header search): the tasks whose title or description holds every word of the query, on the boards the
 * viewer may open ({@see SearchBoardScope}).
 *
 * One query reads the matching tasks of those boards that are not archived (boards, archive and words in SQL, escaped
 * `LIKE`), the most recently changed first, at most `search_kit.scan_rows`. Each one then passes TASK_ACCESS_CHECK
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
            return new SearchHits();
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $scan  = max($limit, $this->scanRows);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('IDENTITY(t.board) IN (:boards)')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('boards', $boards->ids())
            ->orderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'ASC')
            ->setMaxResults($scan);
        foreach ($words as $index => $word) {
            $qb->andWhere("(LOWER(t.title) LIKE :word{$index} ESCAPE '!' OR LOWER(t.description) LIKE :word{$index} ESCAPE '!')")
                ->setParameter('word' . $index, SearchText::contains($word));
        }
        /** @var list<Task> $tasks */
        $tasks = $qb->getQuery()->getResult();

        $visible = $this->accessChecker->taskViewDecider($viewer->user, static fn (TaskBoard $board): bool => $boards->has($board->getId()));
        $hits    = [];
        $total   = 0;
        foreach ($tasks as $task) {
            $board = $boards->boards[$task->getBoard()->getId()] ?? null;
            // Only tasks of the searched boards: a task whose board left the set (or a folded id) is never shown.
            if ($board === null || !$visible($task)) {
                continue;
            }
            ++$total;
            if (count($hits) < $limit) {
                $hits[] = $this->mapper->task($task, $board->tenant, $words);
            }
        }

        return new SearchHits($hits, $total, count($tasks) >= $scan || $boards->capped);
    }
}
