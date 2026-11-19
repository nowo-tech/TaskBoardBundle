<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use DateTimeImmutable;

/**
 * Since 2.25.0 (header search): one row of the header search, as plain text (the search box escapes it): never HTML,
 * never a secret. A SearchKit provider turns it into its row type.
 */
final readonly class SearchHit
{
    /** A task row. */
    public const KIND_TASK = 'task';

    /** A board row. */
    public const KIND_BOARD = 'board';

    /**
     * @param string $kind {@see self::KIND_TASK} or {@see self::KIND_BOARD}
     * @param string $id The task or board id
     * @param string $title The task title or the board name (one line, plain text)
     * @param string $url The task or board page (a path)
     * @param string $subtitle Of a task: its `#number` (when numbering is on), board and tenant; of a board: its tenant ('' without one)
     * @param string|null $excerpt A plain-text cut of the description around the first matched word, or null
     * @param DateTimeImmutable $updatedAt Last change of the task or board
     * @param string|null $boardId The task's board (null for a board row)
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $title,
        public string $url,
        public string $subtitle,
        public ?string $excerpt,
        public DateTimeImmutable $updatedAt,
        public ?string $boardId = null,
    ) {
    }
}
