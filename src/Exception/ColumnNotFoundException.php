<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * Thrown when a column id does not belong to the task's board.
 */
final class ColumnNotFoundException extends InvalidArgumentException
{
    public static function forBoard(string $columnId, string $boardId): self
    {
        return new self(sprintf('Column "%s" does not belong to board "%s".', $columnId, $boardId));
    }
}
