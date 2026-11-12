<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScope;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeProviderInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;

/**
 * Since 2.13.0: the host's multi-board scope of the functional tests: the entries a test sets per user id (none for
 * anyone else), and every viewer it was asked about.
 */
final class ScriptedMultiBoardScope implements MultiBoardScopeProviderInterface
{
    /** @var array<string, list<MultiBoardScopeEntry>> */
    public array $entries = [];

    /** @var list<MultiBoardViewer> */
    public array $asked = [];

    public function readableBoards(MultiBoardViewer $viewer): MultiBoardScope
    {
        $this->asked[] = $viewer;

        return new MultiBoardScope($this->entries[$viewer->userId] ?? []);
    }
}
