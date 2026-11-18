<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;
use Nowo\TaskBoardBundle\ValueObject\TaskSnapshot;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.recipient_resolver`): who a question may be addressed to, and which
 * recipients a signed-in user answers for. Framework-free: values in, values out (a DDD host's user object is the only
 * object it gets). The default, {@see DefaultTaskQuestionRecipientResolver}, offers the task's mention candidates as
 * `user:<id>`.
 *
 * Every id it returns is checked (1–191 characters, no control or format characters, no whitespace at its edges): a bad
 * one is dropped, never stored.
 */
interface TaskQuestionRecipientResolverInterface
{
    /**
     * Who `$asker` may ask on this task (the picker of the ask form, and the check of every ask).
     *
     * @return list<TaskQuestionRecipientCandidate>
     */
    public function candidates(TaskSnapshot $task, TaskBoardSnapshot $board, object $asker): array;

    /**
     * The subject ids `$user` answers for (the answer check and *my open questions*). `$tenantKey`: the tenant of the page
     * or of the question's board (null: none).
     *
     * @return list<string>
     */
    public function subjectIdsOf(object $user, ?string $tenantKey): array;
}
