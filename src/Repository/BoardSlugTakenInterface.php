<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Repository;

/**
 * Since 2.15.1 (REQ-SEC-006, audit OM TB-L1), optional on a board repository: whether the unique `(tenant_key, slug)` key
 * would refuse this pair. Unlike the lookups, which keep only exact rows, any row the database matches counts: before the
 * UPGRADING ALTER the key still folds (`Bob` / `bob`), so the board creator suffixes the slug instead of failing.
 */
interface BoardSlugTakenInterface
{
    public function isSlugTaken(string $slug, ?string $tenantKey): bool;
}
