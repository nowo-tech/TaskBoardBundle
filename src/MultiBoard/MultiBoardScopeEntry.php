<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use InvalidArgumentException;

use function mb_strlen;
use function sprintf;
use function trim;

/**
 * Since 2.13.0: one board the host lets the viewer read in the multi-board task list. `label` is what the Board / Project
 * column shows (null: the board's name); `tenantSlug` is the `{tenantSlug}` of the board's pages with `tenancy.enabled`
 * (the task links then go to the tenant copy of the task route).
 */
final readonly class MultiBoardScopeEntry
{
    /** Longest board id (the bundle's ids are 36-character UUIDs). */
    public const MAX_BOARD_ID_LENGTH = 64;

    /** Longest label. */
    public const MAX_LABEL_LENGTH = 255;

    /** Longest tenant slug (the tenant key column is 191 characters). */
    public const MAX_SLUG_LENGTH = 191;

    public ?string $label;

    public ?string $tenantSlug;

    public function __construct(
        public string $boardId,
        ?string $label = null,
        ?string $tenantSlug = null,
    ) {
        if ($boardId === '' || mb_strlen($boardId) > self::MAX_BOARD_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf('A multi-board scope entry needs a board id of 1 to %d characters.', self::MAX_BOARD_ID_LENGTH));
        }
        $label = $label !== null ? trim($label) : null;
        if ($label !== null && mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidArgumentException(sprintf('A multi-board scope label has at most %d characters.', self::MAX_LABEL_LENGTH));
        }
        if ($tenantSlug !== null && ($tenantSlug === '' || mb_strlen($tenantSlug) > self::MAX_SLUG_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A multi-board scope tenant slug has 1 to %d characters.', self::MAX_SLUG_LENGTH));
        }
        $this->label      = $label === '' ? null : $label;
        $this->tenantSlug = $tenantSlug;
    }
}
