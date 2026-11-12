<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use DateTimeImmutable;

/**
 * Since 1.15: form payload of the "Log time" dialog (minutes, the day they were spent and an optional note; since 2.12.0
 * the billable switch, preset to the host default).
 */
final class TaskTimeEntryFormData
{
    public function __construct(
        public ?int $minutes = null,
        public ?DateTimeImmutable $loggedAt = null,
        public ?string $note = null,
        public bool $billable = false,
    ) {
    }
}
