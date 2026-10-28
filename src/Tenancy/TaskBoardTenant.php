<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

/**
 * A host tenant of the task boards (`tenancy`): the key stored on its boards, the slug in its URLs and the name
 * shown for it (e.g. a project of the host: its id, its URL id and its name).
 */
final readonly class TaskBoardTenant
{
    /**
     * Creates the tenant.
     *
     * @param string $key Stored on the tenant's boards (`TaskBoard::tenantKey`), at most 191 characters
     * @param string $slug The `{tenantSlug}` of the tenant's URLs
     * @param string $label Name of the tenant (the board name of `tenancy.single_board`)
     */
    public function __construct(
        public string $key,
        public string $slug,
        public string $label,
    ) {
    }
}
