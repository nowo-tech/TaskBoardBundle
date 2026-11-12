<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0: the provider without `multi_board.scope_provider`: no board for anyone (deny by default).
 */
final readonly class NullMultiBoardScopeProvider implements MultiBoardScopeProviderInterface
{
    public function readableBoards(MultiBoardViewer $viewer): MultiBoardScope
    {
        return MultiBoardScope::none();
    }
}
