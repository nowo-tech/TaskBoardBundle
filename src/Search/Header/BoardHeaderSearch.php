<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Event\SearchScopeEvent;

use function array_slice;
use function count;
use function max;
use function min;

/**
 * Since 2.25.0 (header search): the boards whose name holds every word of the query, among the boards the viewer may
 * open ({@see SearchBoardScope}: scope, archive and words in SQL, then BOARD_ACCESS_CHECK on each). The rows are cut to
 * the limit after the check and the total counts only visible boards. Stateless (FrankenPHP worker mode).
 */
final readonly class BoardHeaderSearch
{
    public function __construct(
        private SearchBoardScope $scope,
        private SearchHitMapper $mapper,
    ) {
    }

    public function search(SearchViewer $viewer, string $text, int $limit): SearchHits
    {
        $words = SearchText::words($text);
        if ($words === []) {
            return new SearchHits();
        }
        $boards = $this->scope->boards($viewer, SearchScopeEvent::GROUP_BOARDS, $words);
        $limit  = max(1, min($limit, TaskHeaderSearch::MAX_LIMIT));
        $hits   = [];
        foreach (array_slice($boards->boards, 0, $limit) as $board) {
            $hits[] = $this->mapper->board($board->board, $board->tenant);
        }

        return new SearchHits($hits, count($boards->boards), $boards->capped);
    }
}
