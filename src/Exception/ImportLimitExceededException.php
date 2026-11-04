<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use InvalidArgumentException;
use Symfony\Component\Translation\TranslatableMessage;

use function sprintf;

/**
 * Since 1.22.1: an import file is over one of the `import.*` limits. The message is English (logs, console); the
 * translation key and its parameters give the same text in the user's language (flash).
 */
final class ImportLimitExceededException extends InvalidArgumentException
{
    public const ROWS    = 'task_board.import.error.too_many_rows';
    public const CELL    = 'task_board.import.error.cell_too_long';
    public const COLUMNS = 'task_board.import.error.too_many_columns';
    public const CELLS   = 'task_board.import.error.too_many_cells';

    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly array $parameters,
    ) {
        parent::__construct($message);
    }

    /** More data rows than `import.max_rows` (`$rows` null: counting stopped at the limit). */
    public static function rows(int $max, ?int $rows = null): self
    {
        return new self(
            $rows === null
                ? sprintf('The file has more than %d rows; an import takes at most %d.', $max, $max)
                : sprintf('The file has %d rows; an import takes at most %d.', $rows, $max),
            self::ROWS,
            ['%max%' => (string) $max],
        );
    }

    /** A cell longer than `import.max_cell_length` bytes. */
    public static function cellLength(int $max): self
    {
        return new self(sprintf('A cell of the file is longer than %d bytes.', $max), self::CELL, ['%max%' => (string) $max]);
    }

    /** The header (or a row) has more columns than `import.max_columns`. */
    public static function columns(int $max): self
    {
        return new self(sprintf('The file has more than %d columns; an import takes at most %d.', $max, $max), self::COLUMNS, ['%max%' => (string) $max]);
    }

    /** More non-empty cells than `import.max_cells`. */
    public static function cells(int $max): self
    {
        return new self(sprintf('The file has more than %d filled cells; an import takes at most %d.', $max, $max), self::CELLS, ['%max%' => (string) $max]);
    }

    public function translatable(): TranslatableMessage
    {
        return new TranslatableMessage($this->translationKey, $this->parameters, 'NowoTaskBoardBundle');
    }
}
