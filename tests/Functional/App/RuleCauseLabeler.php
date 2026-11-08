<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;

use function str_starts_with;
use function substr;

/** Since 2.3.0 (spec 216): a host's history label, "rule: <id>" for a rule's cause, nothing for any other. */
final class RuleCauseLabeler implements TaskChangeCauseLabelerInterface
{
    public function label(string $cause, TaskChangeHistory $entry): ?string
    {
        return str_starts_with($cause, 'rule:') ? 'rule: <' . substr($cause, 5) . '>' : null;
    }
}
