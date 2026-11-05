<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use Nowo\TaskBoardBundle\Sync\Provider\TimeAccess;

/**
 * Since 1.25: the bounded people pre-scan (always `partial`: a sample of the workspace, the first task pages and the
 * first comments). `calls` is the number of provider requests it spent.
 */
final readonly class PeopleScanResult
{
    /**
     * @param list<ScannedPerson> $people
     */
    public function __construct(
        public array $people,
        public ?TimeAccess $timeAccess,
        public int $calls,
        public ?string $directoryId = null,
        public bool $partial = true,
    ) {
    }
}
