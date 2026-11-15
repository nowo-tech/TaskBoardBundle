<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Configuration;

use RuntimeException;

/**
 * Since 2.19.0: a set of columns could not be applied to a board without leaving tasks in a column it removes; nothing was
 * written. `columns` names them; give a column map (current column id or name => new column name) to move their tasks.
 */
final class BoardColumnsRefusedException extends RuntimeException
{
    /**
     * @param list<string> $columns the names of the columns that hold tasks and would be removed
     */
    public function __construct(
        public readonly string $boardId,
        public readonly array $columns,
        string $reason = 'columns_in_use',
    ) {
        parent::__construct($reason);
    }
}
