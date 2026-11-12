<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScope;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeEntry;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardScopeProviderInterface;
use Nowo\TaskBoardBundle\MultiBoard\MultiBoardViewer;

use function in_array;

/**
 * Since 2.13.0: the adapter of a DDD host (the DDD host kernel test). Its domain knows projects and memberships by plain
 * ids; it never sees a Symfony user, a request or a bundle entity, only the bundle's value objects.
 */
final class DomainProjectBoardScope implements MultiBoardScopeProviderInterface
{
    /** @var array<string, array{name: string, boardId: string, members: list<string>, organisation: string}> project id => project */
    public array $projects = [];

    public function readableBoards(MultiBoardViewer $viewer): MultiBoardScope
    {
        $entries = [];
        foreach ($this->projects as $projectId => $project) {
            if (in_array($viewer->userId, $project['members'], true) && ($viewer->context('organisation') ?? $project['organisation']) === $project['organisation']) {
                $entries[] = new MultiBoardScopeEntry($project['boardId'], $project['name'], (string) $projectId);
            }
        }

        return new MultiBoardScope($entries);
    }
}
