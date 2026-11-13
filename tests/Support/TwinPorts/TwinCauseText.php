<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Support\TwinPorts;

use Nowo\TaskBoardBundle\Comment\TaskCommentCauseTextInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;
use Nowo\TaskBoardBundle\Tests\Functional\App\RuleCauseLabeler;
use Nowo\TaskBoardBundle\ValueObject\TaskChangeSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskCommentSnapshot;

final readonly class TwinCauseText implements TaskCommentCauseTextInterface, TaskChangeCauseTextInterface
{
    public function __construct(private RuleCauseLabeler $legacy, private TwinLookup $lookup)
    {
    }

    public function label(string $cause, TaskCommentSnapshot|TaskChangeSnapshot $entry): ?string
    {
        return $this->legacy->label($cause, $entry instanceof TaskCommentSnapshot ? $this->lookup->comment($entry->id) : $this->lookup->change($entry->id));
    }
}
