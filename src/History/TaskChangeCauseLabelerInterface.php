<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\History;

use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;

/**
 * Since 2.3.0 (spec 216, FR-013): `history.cause_labeler` — how the task's history shows why a change happened (its
 * `cause`, e.g. "rule:42"), next to who made it: "(rule: Review goes to Luis)". Return the text, which is escaped, or null
 * to show nothing. Without a labeler no cause is shown.
 */
interface TaskChangeCauseLabelerInterface
{
    public function label(string $cause, TaskChangeHistory $entry): ?string;
}
