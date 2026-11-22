<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\SearchKit;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchBadge;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\SearchCandidateContext;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Search\Header\SearchBoardScope;
use Nowo\TaskBoardBundle\Search\Header\SearchText;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;
use Nowo\TaskBoardBundle\Search\Header\TaskReference;
use Nowo\TaskBoardBundle\Search\Header\TaskSearchExtras;
use Nowo\TaskBoardBundle\Search\Header\TaskTextMatch;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use SortDirection;

use function array_filter;
use function array_values;
use function implode;
use function is_array;

/**
 * Since 2.25.0: the SearchKit group `tasks` — task title and description, on the boards the viewer may open.
 *
 * baseQuery() reads the boards through {@see SearchBoardScope} (the SEARCH_SCOPE
 * event, tenants, archived boards and BOARD_ACCESS_CHECK) and keeps, in SQL, the tasks of those boards that are not
 * archived. filterVisible() then asks TASK_ACCESS_CHECK once per candidate
 * ({@see TaskBoardResourceAccessChecker::taskViewDecider()}), so host rules apply.
 *
 * Since 2.26.0: the text condition is {@see TaskTextMatch} (title words, description words of
 * `search_kit.description_min_length` characters or more, FULLTEXT where enabled, and a task number, key or external id,
 * number matches first), and the rows carry the column (or "Done") as their badge and the assignee initials, loaded once
 * per page in prepareItems().
 */
final class TaskSearchDefinition extends TaskBoardSearchDefinition
{
    /** Since 2.26.0: the relevance of a task the query names by number or key (above any recency boost). */
    public const NUMBER_MATCH_SCORE = 10.0;

    /** The SearchItemContext key of the assignee initials. */
    private const ASSIGNEES = 'task_board_assignees';

    public function group(): string
    {
        return SearchScopeEvent::GROUP_TASKS;
    }

    public function label(SearchQuery $query): string
    {
        return $this->translated('task_board.search.group.tasks', 'Tasks', $query);
    }

    public function icon(): string
    {
        return 'tabler:checkbox';
    }

    public function subjectType(): string
    {
        return 'task_board_task';
    }

    public function searchableFields(): array
    {
        return ['t.title', 't.description'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        return $this->query($query, null);
    }

    /** SearchKit >= 1.11: the same query; a board scan that stopped at its bound marks the search partial (an inexact total). */
    public function candidateQuery(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): QueryBuilder
    {
        return $this->query($query, $context);
    }

    private function query(SearchQuery $query, ?SearchCandidateContext $context): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t', 'searchColumn')
            ->from(Task::class, 't')
            // Since 2.26.0: the column of each row (its status badge) in the same query.
            ->leftJoin('t.column', 'searchColumn')
            ->where('t.archivedAt IS NULL')
            ->orderBy('t.updatedAt', SortDirection::Descending)
            ->addOrderBy('t.id', SortDirection::Ascending);
        $viewer = $this->viewer($query);
        $scope  = $viewer instanceof SearchViewer ? $this->scope->boards($viewer, SearchScopeEvent::GROUP_TASKS) : null;
        if ($scope?->capped === true) {
            $context?->markPartial();
        }
        $boards = $scope?->ids() ?? [];

        return $boards === [] ? $qb->andWhere('1 = 0') : $qb->andWhere('IDENTITY(t.board) IN (:taskBoardIds)')->setParameter('taskBoardIds', $boards);
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $viewer = $this->viewer($query);
        if (!$viewer instanceof SearchViewer) {
            return [];
        }
        // The boards were checked in baseQuery(): every candidate is on one of them, in SQL.
        $visible = $this->resourceAccess->taskViewDecider($viewer->user, static fn (): bool => true);

        return array_values(array_filter($rows, static fn (array|object $row): bool => $row instanceof Task && $visible($row)));
    }

    /**
     * Since 2.26.0: the text condition of {@see TaskTextMatch} instead of SearchKit's LIKE on every field — title words,
     * description words of `search_kit.description_min_length` characters or more (FULLTEXT where enabled), and a task
     * number, key or external id (number matches first). Always applied (true).
     *
     * @param list<string> $terms
     */
    public function applyFullText(DbalQueryBuilder|QueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        if (!$queryBuilder instanceof QueryBuilder) {
            return false;
        }
        $this->match->apply($queryBuilder, $query->text);

        return true;
    }

    /**
     * Since 2.26.0 (SearchKit >= 1.10): the assignees of the shown rows, in one query ({@see TaskSearchExtras}); kept in
     * the context of this render only.
     *
     * @param list<array<string, mixed>|object> $shownRows
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
        $tasks = array_values(array_filter($shownRows, static fn (array|object $row): bool => $row instanceof Task));
        $context->set(self::ASSIGNEES, $this->extras?->assigneeInitials($tasks) ?? []);
    }

    /** Since 2.26.0: the row with its status badge and assignee initials (from prepareItems()). */
    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $assignees = $context->get(self::ASSIGNEES, []);

        return $this->item($row, $query, is_array($assignees) && $row instanceof Task && is_array($assignees[$row->getId()] ?? null) ? array_values(array_filter($assignees[$row->getId()], is_string(...))) : []);
    }

    /** The row without the assignees (a caller that does not run prepareItems()). */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        return $this->item($row, $query, []);
    }

    /**
     * The palette row: the title; the number, board and tenant, the description excerpt and the assignee initials in the
     * subtitle; "Done" (success) or the column name in the badge; a number match scores first.
     *
     * @param array<string, mixed>|object $row
     * @param list<string> $assignees
     */
    private function item(array|object $row, SearchQuery $query, array $assignees): SearchItem
    {
        $viewer = $this->viewer($query);
        if (!$row instanceof Task || !$viewer instanceof SearchViewer) {
            throw new LogicException('A task row of the signed-in viewer is expected.');
        }
        $hit   = $this->mapper->task($row, $this->tenantOf($row->getBoard(), $viewer), SearchText::words($query->text), $assignees, TaskReference::parse($query->text));
        $badge = $hit->done
            ? new SearchBadge($this->translated('task_board.search.done', 'Done', $query), 'success')
            : ($hit->status !== null ? new SearchBadge($hit->status) : null);

        return new SearchItem(
            $hit->title,
            $hit->url,
            self::subtitle([$hit->subtitle, $hit->excerpt, $hit->assignees !== [] ? implode(', ', $hit->assignees) : null]),
            badge: $badge,
            score: $hit->byNumber ? self::NUMBER_MATCH_SCORE : null,
            date: $hit->updatedAt,
        );
    }

    /** No list page searches tasks by text across boards: no "see all" link. */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
    }

    public function idField(): string
    {
        return 't.id';
    }

    protected function referenceRoute(): array
    {
        return ['task', 'taskId'];
    }
}
