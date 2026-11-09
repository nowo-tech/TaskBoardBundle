<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

use Nowo\TaskBoardBundle\Entity\TaskBoard;

/**
 * Since 2.10.0 (DDD audit G4): the port of {@see TaskBoardEditor}, which renames a board and edits its description. A
 * host's application layer depends on it and can double or decorate it; the container aliases it to the bundle's class.
 * The bundle's own controllers keep using the class, so a host decorator wraps the host's calls only.
 */
interface TaskBoardEditorInterface
{
    public function update(TaskBoard $board, string $name, ?string $description, object $actor): void;
}
