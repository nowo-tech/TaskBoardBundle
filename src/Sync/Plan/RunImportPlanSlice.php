<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

/**
 * Since 3.2.0 (CU-2, Q-CU-9): the Messenger message of the optional plan runner — ids only (never a credential).
 * Route it to an async transport in the host's `messenger.routing`.
 */
final readonly class RunImportPlanSlice
{
    public function __construct(
        public string $planId,
        public ?string $actorId,
    ) {
    }
}
