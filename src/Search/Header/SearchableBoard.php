<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Search\Header;

use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;

/**
 * Since 2.25.0 (header search): a board the viewer may open, with its tenant (null without tenancy).
 *
 * @internal
 */
final readonly class SearchableBoard
{
    public function __construct(
        public TaskBoard $board,
        public ?TaskBoardTenant $tenant = null,
    ) {
    }
}
