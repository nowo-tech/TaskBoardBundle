<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Dto\BoardColumnFormData;
use Nowo\TaskBoardBundle\Entity\BoardColumn;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Repository\BoardColumnRepositoryInterface;

/**
 * Adds and reorders kanban columns on a board.
 */
final readonly class BoardColumnManager
{
    public function __construct(
        private BoardColumnRepositoryInterface $columnRepository,
    ) {
    }

    public function add(TaskBoard $board, BoardColumnFormData $data): BoardColumn
    {
        $position = -1;
        foreach ($board->getColumns() as $column) {
            $position = max($position, $column->getPosition());
        }

        $column = new BoardColumn(
            board: $board,
            name: trim($data->name),
            position: $position + 1,
            color: $this->normalizeColor($data->color),
        );

        $board->addColumn($column);
        $this->columnRepository->save($column);

        return $column;
    }

    /**
     * @param list<string> $orderedColumnIds
     */
    public function reorder(TaskBoard $board, array $orderedColumnIds): void
    {
        /** @var array<string, BoardColumn> $byId */
        $byId = [];
        foreach ($board->getColumns() as $column) {
            $byId[$column->getId()] = $column;
        }

        $updated  = [];
        $position = 0;

        foreach ($orderedColumnIds as $columnId) {
            if (!isset($byId[$columnId])) {
                continue;
            }

            $byId[$columnId]->setPosition($position);
            $updated[] = $byId[$columnId];
            unset($byId[$columnId]);
            ++$position;
        }

        foreach ($byId as $column) {
            $column->setPosition($position);
            $updated[] = $column;
            ++$position;
        }

        if ($updated !== []) {
            $this->columnRepository->saveAll($updated);
        }
    }

    public function update(BoardColumn $column, BoardColumnFormData $data): void
    {
        $column
            ->setName(trim($data->name))
            ->setColor($this->normalizeColor($data->color));

        $this->columnRepository->save($column);
    }

    private function normalizeColor(?string $color): ?string
    {
        if ($color === null) {
            return null;
        }

        $color = trim($color);

        return $color === '' ? null : $color;
    }
}
