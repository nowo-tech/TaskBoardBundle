<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): a recipient of a question — the host's opaque subject id, the label it had when the
 * question was asked, and whether it answers through the host's signed link.
 */
final readonly class TaskQuestionRecipientSnapshot
{
    public function __construct(
        public string $subjectId,
        public string $label,
        public bool $external,
    ) {
    }
}
