<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Provider;

/**
 * Since 1.25: the time mode of a token in a workspace and the token user's id (never the token).
 */
final readonly class TimeAccess
{
    public function __construct(
        public TimeAccessMode $mode,
        public ?string $tokenUserId = null,
    ) {
    }
}
