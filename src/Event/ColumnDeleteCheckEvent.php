<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Event;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Support\ActorId;
use Symfony\Contracts\EventDispatcher\Event;

use function in_array;
use function trim;

/**
 * Since 2.10.0 (spec 216, FR-010): {@see TaskBoardEvents::COLUMN_DELETE_CHECK} — the board page is about to offer "Delete column"
 * for these columns. A listener adds warning lines per column ("2 automation rules use this column; they will pause"); the
 * delete dialog shows them and its confirmation repeats them. Plain text (escaped). Dispatched once per board page, for a
 * person who may manage the columns; it never refuses the deletion.
 */
final class ColumnDeleteCheckEvent extends Event
{
    /** @var array<string, list<string>> */
    private array $warnings = [];

    /**
     * @param list<string> $columnIds the board's columns, in order
     * @param object $user who views the board (deprecated since 2.29.0, use {@see self::getUserId()}; removed in 3.0)
     */
    public function __construct(
        public readonly string $boardId,
        public readonly ?string $tenantKey,
        public readonly array $columnIds,
        public readonly object $user,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    /** Since 2.29.0 (DDD-host checklist B4). */
    public readonly DateTimeImmutable $occurredAt;

    /** Since 2.29.0: the id of the user who views the board (`getId()` as a string; null without a usable id). */
    public function getUserId(): ?string
    {
        return ActorId::of($this->user);
    }

    /** Since 2.29.0. */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Adds a warning for a column of this board; an unknown column or a blank text is ignored. */
    public function addWarning(string $columnId, string $message): void
    {
        $message = trim($message);
        if ($message === '' || !in_array($columnId, $this->columnIds, true)) {
            return;
        }
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->warnings[$columnId][] = $message;
    }

    /** @return list<string> */
    public function getWarnings(string $columnId): array
    {
        return $this->warnings[$columnId] ?? [];
    }

    /** @return array<string, list<string>> by column id */
    public function getAllWarnings(): array
    {
        return $this->warnings;
    }
}
