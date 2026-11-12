<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Calendar;

/**
 * Since 2.14.0: who looks at a board's calendar, as plain values (REQ-ARCH-001 rule 3): the user id (`user_class::getId()`
 * as a string), the board and its tenant key.
 */
final readonly class CalendarViewer
{
    public function __construct(
        public string $userId,
        public string $boardId,
        public ?string $tenantKey = null,
    ) {
    }
}
