<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\SearchKit;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Search\Header\SearchBoardScope;
use Nowo\TaskBoardBundle\Search\Header\SearchText;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;

use function array_filter;
use function array_values;

/**
 * Since 2.25.0: the SearchKit group `tasks` — task title and description, on the boards the viewer may open.
 *
 * baseQuery() reads the boards through {@see SearchBoardScope} (the SEARCH_SCOPE
 * event, tenants, archived boards and BOARD_ACCESS_CHECK) and keeps, in SQL, the tasks of those boards that are not
 * archived. filterVisible() then asks TASK_ACCESS_CHECK once per candidate
 * ({@see TaskBoardResourceAccessChecker::taskViewDecider()}), so host rules apply.
 */
final class TaskSearchDefinition extends TaskBoardSearchDefinition
{
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
        return 'task_board.task';
    }

    public function searchableFields(): array
    {
        return ['t.title', 't.description'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Task::class, 't')
            ->where('t.archivedAt IS NULL')
            ->orderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'ASC');
        $viewer = $this->viewer($query);
        $boards = $viewer instanceof SearchViewer ? $this->scope->boards($viewer, SearchScopeEvent::GROUP_TASKS)->ids() : [];

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

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $viewer = $this->viewer($query);
        if (!$row instanceof Task || !$viewer instanceof SearchViewer) {
            throw new LogicException('A task row of the signed-in viewer is expected.');
        }
        $hit = $this->mapper->task($row, $this->tenantOf($row->getBoard(), $viewer), SearchText::words($query->text));

        return new SearchItem($hit->title, $hit->url, self::subtitle([$hit->subtitle, $hit->excerpt]), date: $hit->updatedAt);
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
