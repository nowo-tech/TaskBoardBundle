<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use InvalidArgumentException;

use function array_map;
use function get_debug_type;
use function sprintf;

/**
 * Since 2.13.0: the boards a {@see MultiBoardScopeProviderInterface} lets the viewer read, in the order the host wants
 * them offered. A board listed twice keeps its first entry. Empty ({@see self::none()}) means nothing at all: the list is
 * empty and the export answers 404. The bundle still checks every board with its own read access check.
 */
final readonly class MultiBoardScope
{
    /** @var list<MultiBoardScopeEntry> */
    public array $entries;

    /**
     * @param iterable<MultiBoardScopeEntry> $entries
     */
    public function __construct(iterable $entries = [])
    {
        $unique = [];
        foreach ($entries as $entry) {
            // @phpstan-ignore instanceof.alwaysTrue (a host's provider is plain PHP: refuse anything else loudly)
            if (!$entry instanceof MultiBoardScopeEntry) {
                throw new InvalidArgumentException(sprintf('A multi-board scope holds %s, %s given.', MultiBoardScopeEntry::class, get_debug_type($entry)));
            }
            $unique[$entry->boardId] ??= $entry;
        }
        $list = [];
        foreach ($unique as $entry) {
            $list[] = $entry;
        }
        $this->entries = $list;
    }

    /** No board: deny by default. */
    public static function none(): self
    {
        return new self();
    }

    /** Boards by id only (labels: the board names). */
    public static function ofBoardIds(string ...$boardIds): self
    {
        return new self(array_map(static fn (string $id): MultiBoardScopeEntry => new MultiBoardScopeEntry($id), $boardIds));
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<string>
     */
    public function boardIds(): array
    {
        return array_map(static fn (MultiBoardScopeEntry $entry): string => $entry->boardId, $this->entries);
    }
}
