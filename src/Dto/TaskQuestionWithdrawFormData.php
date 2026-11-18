<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use function preg_match;

/**
 * Since 2.23.0 (Beacon spec 242): form payload of *Withdraw* — the reason (required) and the question's version read.
 */
final class TaskQuestionWithdrawFormData
{
    public function __construct(
        public string $reason = '',
        public ?string $version = null,
    ) {
    }

    /** The version read, or null when the field is missing or not a positive integer. */
    public function versionNumber(): ?int
    {
        return $this->version !== null && preg_match('/^[1-9][0-9]{0,9}$/D', $this->version) === 1 && (int) $this->version <= 2147483647 ? (int) $this->version : null;
    }
}
