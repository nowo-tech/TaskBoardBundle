<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.decision_publisher`): the *Publish decision* action of an answered
 * question — the host decides the target (e.g. a wiki page) and writes it. The bundle asks {@see self::targetLabel()} to
 * show the button, calls {@see self::publish()} outside its own transaction, then stores the result on the question.
 */
interface TaskQuestionDecisionPublisherInterface
{
    /** The button's label (plain text, e.g. "Publish to wiki"); null hides it for this question and user. */
    public function targetLabel(TaskQuestionSnapshot $question, object $user): ?string;

    /** Publishes the decision; a failure is the host's exception and the bundle saves nothing. */
    public function publish(TaskQuestionSnapshot $question, TaskQuestionAnswerSnapshot $answer, object $user): TaskQuestionPublication;
}
