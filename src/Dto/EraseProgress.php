<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Service\TaskBoardEraser;

/**
 * Since 1.25: one slice of {@see TaskBoardEraser}. `deleted` = tasks removed by this
 * call; `remaining` = tasks still on the board; call again with `cursor` until `done`.
 */
final readonly class EraseProgress
{
    public function __construct(
        public int $deleted,
        public int $remaining,
        public bool $done,
        public ?EraseCursor $cursor,
    ) {
    }
}
