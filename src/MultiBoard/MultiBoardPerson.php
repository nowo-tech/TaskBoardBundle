<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

/**
 * Since 2.13.0: an assignee as the multi-board list shows it: the user id (`user_class::getId()` as a string) and its label.
 */
final readonly class MultiBoardPerson
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
