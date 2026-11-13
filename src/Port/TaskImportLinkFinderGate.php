<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Port;

use Nowo\TaskBoardBundle\DependencyInjection\Compiler\TaskBoardPortsPass;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Import\TaskImportLinkFinderInterface;
use Nowo\TaskBoardBundle\Import\TaskImportLinkLookupInterface;

/**
 * Since 2.15.0: asks a host {@see TaskImportLinkFinderInterface} where the bundle asks the deprecated {@see TaskImportLinkLookupInterface}. Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports} / {@see TaskBoardPortsPass}
 */
final readonly class TaskImportLinkFinderGate implements TaskImportLinkLookupInterface
{
    public function __construct(private TaskImportLinkFinderInterface $finder)
    {
    }

    public function findOnOtherBoards(TaskBoard $board, string $scope, array $externalIds): array
    {
        return $this->finder->findOnOtherBoards(Snapshots::board($board), $scope, $externalIds);
    }
}
