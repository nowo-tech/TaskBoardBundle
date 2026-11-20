<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Support\ExactKey;

use function mb_strtoupper;
use function preg_match;
use function trim;

/**
 * Since 2.26.0 (header search): what a query may name besides words — a task number (`#123`, `123`), a board-prefixed
 * key (`DEV-123`, the board's numbering prefix and the number) or an external id (the id an import or a provider sync
 * stored on the task, e.g. a ClickUp id). Only a query of one token is a reference. Plain values for one search.
 */
final readonly class TaskReference
{
    /** The highest number a reference may name (the `number` column is an integer). */
    public const MAX_NUMBER = 2147483647;

    /** The length of `task_board_task_links.external_id`. */
    public const EXTERNAL_ID_LENGTH = 128;

    public function __construct(
        public ?int $number = null,
        public ?string $prefix = null,
        public ?string $externalId = null,
    ) {
    }

    /** The reference a query names, or null (several words, or nothing a task could be found by). */
    public static function parse(string $text): ?self
    {
        $text = trim($text);
        if ($text === '' || preg_match('/\s/u', $text) === 1) {
            return null;
        }
        $number = null;
        $prefix = null;
        if (preg_match('/^#?(\d{1,10})$/D', $text, $match) === 1) {
            $number = (int) $match[1];
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9_-]{0,15})-(\d{1,10})$/D', $text, $match) === 1
            && preg_match(TaskBoard::NUMBER_PREFIX_PATTERN, mb_strtoupper($match[1])) === 1) {
            $number = (int) $match[2];
            $prefix = mb_strtoupper($match[1]);
        }
        if ($number !== null && ($number < 1 || $number > self::MAX_NUMBER)) {
            $number = null;
            $prefix = null;
        }
        $externalId = ExactKey::isUsable($text, self::EXTERNAL_ID_LENGTH) ? $text : null;

        return $number === null && $externalId === null ? null : new self($number, $prefix, $externalId);
    }

    /** Whether the task is the one the number (and prefix) names: such a match ranks first. */
    public function namesByNumber(Task $task): bool
    {
        if ($this->number === null || $task->getNumber() !== $this->number) {
            return false;
        }

        return $this->prefix === null || $task->getBoard()->getNumberPrefix() === $this->prefix;
    }
}
