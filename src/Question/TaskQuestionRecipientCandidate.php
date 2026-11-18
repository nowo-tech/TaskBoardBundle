<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): someone a question may be addressed to, as the host's
 * {@see TaskQuestionRecipientResolverInterface} names them — an opaque subject id (1–191 characters, compared byte for
 * byte), the label shown (plain text) and whether they answer through the host's signed link (`external`).
 */
final readonly class TaskQuestionRecipientCandidate
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $external = false,
    ) {
    }
}
