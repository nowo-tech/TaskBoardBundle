<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tenancy;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Support\ExactKey;

/**
 * A host tenant of the task boards (`tenancy`): the key stored on its boards, the slug in its URLs and the name
 * shown for it (e.g. a project of the host: its id, its URL id and its name).
 */
final readonly class TaskBoardTenant
{
    /**
     * Creates the tenant.
     *
     * @param string $key Stored on the tenant's boards (`TaskBoard::tenantKey`), at most 191 characters; since 2.15.1
     *                    (REQ-SEC-006) refused when it cannot be matched exactly ({@see ExactKey::isUsable()})
     * @param string $slug The `{tenantSlug}` of the tenant's URLs
     * @param string $label Name of the tenant (the board name of `tenancy.single_board`)
     *
     * @throws InvalidArgumentException when the key cannot be matched exactly (since 2.15.1)
     */
    public function __construct(
        public string $key,
        public string $slug,
        public string $label,
        // Since 1.30.0 (spec 211): shown beside the label in board lists, escaped.
        public ?string $icon = null,
    ) {
        ExactKey::assertUsable($key, 'tenant key');
    }
}
