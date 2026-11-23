<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\History;

use Nowo\TaskBoardBundle\ValueObject\TaskChangeSnapshot;

/**
 * Since 2.15.0 (REQ-ARCH-001 rule 1): the framework-free twin of `TaskChangeCauseLabelerInterface` (the 2.14 interface, removed in 3.0): the text a history
 * row shows for its cause, given a {@see TaskChangeSnapshot}. Return the text (escaped) or null to show nothing.
 *
 * Set `history.cause_labeler` to a service implementing it.
 */
interface TaskChangeCauseTextInterface
{
    public function label(string $cause, TaskChangeSnapshot $entry): ?string;
}
