<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\People;

use InvalidArgumentException;

/**
 * Since 1.25: a mapping decision the directory refuses (`not_candidate`, `unknown_person`, `anonymised`,
 * `no_suggestion`, `invalid_kind`). The message carries the code only.
 */
final class InvalidMappingException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('Invalid people mapping: ' . $reason . '.');
    }
}
