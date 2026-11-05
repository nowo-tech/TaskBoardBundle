<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

/**
 * Since 1.25: one decision of the People step: map a person to a user (`manual`), keep them external (`external`), or
 * confirm the suggestion (`confirm`).
 */
final readonly class PersonMappingDecision
{
    public const MANUAL   = 'manual';
    public const EXTERNAL = 'external';
    public const CONFIRM  = 'confirm';

    /** The contract's name of {@see self::CONFIRM}; both are accepted. */
    public const CONFIRM_SUGGESTION = 'confirm_suggestion';

    public function __construct(
        public string $personId,
        public string $kind,
        public ?string $userId = null,
    ) {
    }
}
