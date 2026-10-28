<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\ColumnSet;

/**
 * Persistence port for column sets (since 1.11).
 */
interface ColumnSetRepositoryInterface
{
    public function save(ColumnSet $set): void;

    public function remove(ColumnSet $set): void;

    public function findById(string $id): ?ColumnSet;

    /**
     * The sets of a scope, by name; null: the sets without a scope.
     *
     * @return list<ColumnSet>
     */
    public function findByScope(?string $scopeKey): array;
}
