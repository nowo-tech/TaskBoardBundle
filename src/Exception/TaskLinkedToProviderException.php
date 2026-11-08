<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use RuntimeException;

/**
 * Since 2.2.0 (spec 213): a task that follows a provider, or has a subtask that does, is not erased on its own: the next
 * sync would bring it back. Unlink the board first.
 */
final class TaskLinkedToProviderException extends RuntimeException
{
    public static function forTask(string $taskId): self
    {
        return new self('Task ' . $taskId . ' follows a provider.');
    }
}
