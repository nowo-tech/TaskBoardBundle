<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Comment\TaskCommentCauseLabelerInterface;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;

use function str_starts_with;
use function substr;

/**
 * Since 2.3.0 (spec 216): a host's history label, "rule: <id>" for a rule's cause, nothing for any other. Since 2.10.0 the
 * same service labels comments (`comments.cause_labeler`).
 */
final class RuleCauseLabeler implements TaskChangeCauseLabelerInterface, TaskCommentCauseLabelerInterface
{
    public function label(string $cause, TaskChangeHistory|TaskComment $entry): ?string
    {
        return str_starts_with($cause, 'rule:') ? 'rule: <' . substr($cause, 5) . '>' : null;
    }
}
