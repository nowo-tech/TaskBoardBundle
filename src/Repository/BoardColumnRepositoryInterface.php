<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\BoardColumn;

/**
 * Persistence port for board columns.
 */
interface BoardColumnRepositoryInterface
{
    public function save(BoardColumn $column): void;

    /** @param list<BoardColumn> $columns */
    public function saveAll(array $columns): void;
}
