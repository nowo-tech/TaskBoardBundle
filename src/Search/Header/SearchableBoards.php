<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

/**
 * Since 2.25.0 (header search): the boards a viewer may open within the search scope, by id, and whether the scope
 * held more boards than `search_kit.max_boards` (the rest was left out, so a total may miss rows).
 *
 * @internal
 */
final readonly class SearchableBoards
{
    /**
     * @param array<string, SearchableBoard> $boards by board id
     * @param bool $capped The scope held more boards than were read
     */
    public function __construct(
        public array $boards,
        public bool $capped = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->boards === [];
    }

    /** Whether the board is one of the set. */
    public function has(string $boardId): bool
    {
        return isset($this->boards[$boardId]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map('strval', array_keys($this->boards));
    }
}
