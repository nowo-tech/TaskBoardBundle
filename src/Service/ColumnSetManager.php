<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\ColumnSet;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Event\ColumnSetQueryEvent;
use Nowo\TaskBoardBundle\Event\TaskBoardEvents;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;
use Nowo\TaskBoardBundle\Repository\ColumnSetRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function max;
use function trim;

/**
 * Column sets (since 1.11): lists the sets a user may apply (scoped through {@see ColumnSetQueryEvent}), saves a
 * board's columns as a set, appends a set's columns to a board and deletes sets.
 */
final readonly class ColumnSetManager
{
    public function __construct(
        private ColumnSetRepositoryInterface $setRepository,
        private BoardColumnRepositoryInterface $columnRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * The sets `$user` may apply to `$board` (or to a new board of `$tenantKey` when `$board` is null).
     *
     * @return list<ColumnSet>
     */
    public function available(object $user, ?TaskBoard $board = null, ?string $tenantKey = null): array
    {
        $event = $this->query($user, $board, $tenantKey);

        return $event->getOverrideList() ?? $this->setRepository->findByScope($event->getScopeKey());
    }

    /**
     * One of the sets `$user` may apply (a set of another scope is not found).
     */
    public function find(string $setId, object $user, ?TaskBoard $board = null, ?string $tenantKey = null): ?ColumnSet
    {
        foreach ($this->available($user, $board, $tenantKey) as $set) {
            if ($set->getId() === $setId) {
                return $set;
            }
        }

        return null;
    }

    /**
     * Saves copies of the board's columns as a new set in the scope the listeners chose.
     */
    public function saveFromBoard(TaskBoard $board, string $name, object $user): ColumnSet
    {
        $scope = $this->query($user, $board, $board->getTenantKey())->getScopeKey();
        $set   = ColumnSet::fromBoard($board, trim($name), $user, $scope);
        $this->setRepository->save($set);

        return $set;
    }

    /**
     * Appends copies of the set's columns after the board's columns.
     *
     * @return list<BoardColumn> the new columns
     */
    public function applyToBoard(ColumnSet $set, TaskBoard $board): array
    {
        $position = -1;
        foreach ($board->getColumns() as $column) {
            $position = max($position, $column->getPosition());
        }

        $added = [];
        foreach ($set->getItems() as $item) {
            $column = new BoardColumn($board, $item['name'], ++$position, $item['color'], $item['icon']);
            $board->addColumn($column);
            $added[] = $column;
        }

        if ($added !== []) {
            $this->columnRepository->saveAll($added);
        }

        return $added;
    }

    public function delete(ColumnSet $set): void
    {
        // @igor-ignore - Persistence call on a stateless repository; no worker state.
        $this->setRepository->remove($set);
    }

    private function query(object $user, ?TaskBoard $board, ?string $tenantKey): ColumnSetQueryEvent
    {
        $event = new ColumnSetQueryEvent($user, $board, $tenantKey);
        $this->eventDispatcher?->dispatch($event, TaskBoardEvents::COLUMN_SET_QUERY);

        return $event;
    }
}
