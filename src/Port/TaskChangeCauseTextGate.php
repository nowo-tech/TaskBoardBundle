<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskChangeHistory;
use Nowo\TaskBoardBundle\History\TaskChangeCauseLabelerInterface;
use Nowo\TaskBoardBundle\History\TaskChangeCauseTextInterface;

/**
 * Since 2.15.0: asks a host {@see TaskChangeCauseTextInterface} where the bundle asks the deprecated {@see TaskChangeCauseLabelerInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskChangeCauseTextGate implements TaskChangeCauseLabelerInterface
{
    public function __construct(private TaskChangeCauseTextInterface $text)
    {
    }

    public function label(string $cause, TaskChangeHistory $entry): ?string
    {
        return $this->text->label($cause, Snapshots::change($entry));
    }
}
