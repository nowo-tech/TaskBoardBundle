<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\App;

use Nowo\TaskBoardBundle\Question\TaskQuestionExternalAnswerInterface;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;

/**
 * Beacon spec 242: the host's signed answer links (`contact:bad` gets a non-http link, which the bundle drops).
 */
final class ScriptedQuestionLinks implements TaskQuestionExternalAnswerInterface
{
    public function answerUrl(TaskQuestionSnapshot $question, string $subjectId): ?string
    {
        return match ($subjectId) {
            'contact:none' => null,
            'contact:bad'  => 'javascript:alert(1)',
            default        => 'https://portal.example/answer/' . $question->id . '?s=' . $subjectId . '&sig=x',
        };
    }
}
