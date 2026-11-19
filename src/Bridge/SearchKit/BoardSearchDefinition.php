<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Bridge\SearchKit;

use Doctrine\ORM\QueryBuilder;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\SearchScopeEvent;
use Nowo\TaskBoardBundle\Search\Header\SearchBoardScope;
use Nowo\TaskBoardBundle\Search\Header\SearchText;
use Nowo\TaskBoardBundle\Search\Header\SearchViewer;

use function array_filter;
use function array_values;

/**
 * Since 2.25.0: the SearchKit group `boards` — board names, among the boards the viewer may open.
 *
 * baseQuery() reads the boards through {@see SearchBoardScope} with the words of the
 * name in SQL (so the board cap never hides a match), each one already through BOARD_ACCESS_CHECK, and keeps exactly
 * those ids; filterVisible() keeps them (the check ran once, before the cut).
 */
final class BoardSearchDefinition extends TaskBoardSearchDefinition
{
    public function group(): string
    {
        return SearchScopeEvent::GROUP_BOARDS;
    }

    public function label(SearchQuery $query): string
    {
        return $this->translated('task_board.search.group.boards', 'Boards', $query);
    }

    public function icon(): string
    {
        return 'tabler:layout-kanban';
    }

    public function subjectType(): string
    {
        return 'task_board_board';
    }

    public function searchableFields(): array
    {
        return ['b.name'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(TaskBoard::class, 'b')
            ->where('b.archivedAt IS NULL')
            ->orderBy('b.updatedAt', 'DESC')
            ->addOrderBy('b.id', 'ASC');
        $viewer = $this->viewer($query);
        $boards = $viewer instanceof SearchViewer ? $this->scope->boards($viewer, SearchScopeEvent::GROUP_BOARDS, SearchText::words($query->text))->ids() : [];

        return $boards === [] ? $qb->andWhere('1 = 0') : $qb->andWhere('b.id IN (:taskBoardIds)')->setParameter('taskBoardIds', $boards);
    }

    /** Every candidate is a board baseQuery() checked (BOARD_ACCESS_CHECK) for this viewer. */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return $this->viewer($query) instanceof SearchViewer ? array_values(array_filter($rows, static fn (array|object $row): bool => $row instanceof TaskBoard)) : [];
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $viewer = $this->viewer($query);
        if (!$row instanceof TaskBoard || !$viewer instanceof SearchViewer) {
            throw new LogicException('A board row of the signed-in viewer is expected.');
        }
        $hit = $this->mapper->board($row, $this->tenantOf($row, $viewer));

        return new SearchItem($hit->title, $hit->url, self::subtitle([$hit->subtitle, $hit->excerpt]), date: $hit->updatedAt);
    }

    /** The board index lists boards without a text filter: no "see all" link. */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
    }

    public function idField(): string
    {
        return 'b.id';
    }

    protected function referenceRoute(): array
    {
        return ['board', 'boardId'];
    }
}
