<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\DddHost;

use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScope;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeProviderInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;

/**
 * The DDD host's multi-board scope: the boards a test names, for every viewer (values only).
 */
final class DddMultiBoardScope implements MultiBoardScopeProviderInterface
{
    /** @var list<string> */
    public static array $boardIds = [];

    public function readableBoards(MultiBoardViewer $viewer): MultiBoardScope
    {
        return new MultiBoardScope(array_map(static fn (string $id): MultiBoardScopeEntry => new MultiBoardScopeEntry($id), self::$boardIds));
    }
}
