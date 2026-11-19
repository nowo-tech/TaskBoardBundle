<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

/**
 * Since 2.25.0 (header search): the rows of one group and the number of visible matches.
 *
 * `$total` counts only rows the viewer may open (each one passed the access checks), so it never hints at a hidden row.
 * `$partial` says the scan stopped at its bound (`search_kit.scan_rows` or `search_kit.max_boards`): there may be more
 * visible matches than `$total`.
 */
final readonly class SearchHits
{
    /**
     * @param list<SearchHit> $hits At most the requested limit
     * @param int $total Visible matches found (at least count($hits))
     * @param bool $partial The scan hit its bound
     */
    public function __construct(
        public array $hits = [],
        public int $total = 0,
        public bool $partial = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
