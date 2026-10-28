<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Persistence port for task boards.
 */
interface TaskBoardRepositoryInterface
{
    public function save(TaskBoard $board): void;

    public function remove(TaskBoard $board): void;

    public function findById(string $id): ?TaskBoard;

    /** @return list<TaskBoard> */
    public function findAllActive(): array;

    public function findBySlug(string $slug): ?TaskBoard;

    /**
     * Active boards of a host tenant (`tenancy`), by name.
     *
     * @return list<TaskBoard>
     */
    public function findActiveByTenant(string $tenantKey): array;

    /**
     * The first active board of a host tenant (`tenancy`), oldest first; null when the tenant has none.
     */
    public function findOneByTenant(string $tenantKey): ?TaskBoard;
}
