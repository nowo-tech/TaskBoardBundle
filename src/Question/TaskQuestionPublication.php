<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242): where a decision was published — a URL (http or https, at most 2048 characters) and a
 * label (plain text, at most 180 characters), stored on the question and linked from its card.
 */
final readonly class TaskQuestionPublication
{
    public function __construct(
        public string $url,
        public string $label,
    ) {
    }
}
