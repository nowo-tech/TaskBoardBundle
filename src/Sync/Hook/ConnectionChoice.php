<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

/** Since 1.23: a stored connection the user may pick (never the secret: its last four characters only). */
final readonly class ConnectionChoice
{
    public function __construct(
        public string $ref,
        public string $label,
        public string $lastFour,
    ) {
    }
}
