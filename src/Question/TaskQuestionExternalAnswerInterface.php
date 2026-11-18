<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.external_answers`): the host's signed link where an external recipient
 * (a client contact) answers a question. The bundle prints it on the question card for the asker and the board's
 * managers (*Copy answer link*) and never stores or logs it.
 *
 * The host's own page behind the link checks its signature, then calls
 * {@see TaskQuestionManager::answerAsSubject()}: the signed link is the permission, the bundle checks only that the
 * subject is one of the question's recipients and that it is not withdrawn.
 */
interface TaskQuestionExternalAnswerInterface
{
    /** The URL (http or https) where `$subjectId` answers `$question`; null: none for this recipient. */
    public function answerUrl(TaskQuestionSnapshot $question, string $subjectId): ?string;
}
