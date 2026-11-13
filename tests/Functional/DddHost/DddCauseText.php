<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\ValueObject\TaskChangeSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

use function str_starts_with;
use function substr;

/**
 * The DDD host's cause labels, through the framework-free twins only.
 */
final class DddCauseText implements TaskCommentCauseTextInterface, TaskChangeCauseTextInterface
{
    public function label(string $cause, TaskCommentSnapshot|TaskChangeSnapshot $entry): ?string
    {
        return str_starts_with($cause, 'rule:') ? 'ddd rule ' . substr($cause, 5) : null;
    }
}
