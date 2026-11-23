<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

/**
 * Since 2.32.0 (Beacon request): the question counts of the signed-in viewer, for a host's menu badge (a DDD host
 * depends on this interface rather than on the view). Implemented by {@see TaskQuestionView}; registered with
 * `task_questions.enabled` only. The bundle implements it; a host does not.
 */
interface TaskQuestionCountsInterface
{
    /**
     * How many open questions *My open questions* lists in its `to_answer` tab for the signed-in viewer
     * ({@see TaskQuestionView::countToAnswer()}): the same rules, a constant number of queries. 0 without a viewer.
     */
    public function countToAnswer(): int;
}
