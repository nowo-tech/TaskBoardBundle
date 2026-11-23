<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port\Internal;

use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;

/**
 * Since 2.3.0 (spec 216, FR-013): `history.cause_labeler` — how the task's history shows why a change happened (its
 * `cause`, e.g. "rule:42"), next to who made it: "(rule: Review goes to Luis)". Return the text, which is escaped, or null
 * to show nothing. Without a labeler no cause is shown.
 *
 * Since 3.0.0 the bundle's own entity-typed seam (REQ-ARCH-001 rule 3): it was the host port `Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface`,
 * deprecated in 2.15.0 and removed in 3.0. Hosts implement the framework-free {@see TaskChangeCauseTextInterface};
 * the bundle asks it through its gate, and its own defaults implement this seam. A class outside the bundle that
 * implements this interface is refused when the container is built.
 *
 * @internal
 */
interface TaskChangeCauseLabelerInterface
{
    public function label(string $cause, TaskChangeHistory $entry): ?string;
}
