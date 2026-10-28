<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;

/**
 * Since 1.15: form payload of the "Log time" dialog (minutes, the day they were spent and an optional note).
 */
final class TaskTimeEntryFormData
{
    public function __construct(
        public ?int $minutes = null,
        public ?DateTimeImmutable $loggedAt = null,
        public ?string $note = null,
    ) {
    }
}
