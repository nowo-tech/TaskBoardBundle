<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Operation;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Security\TaskApiGuard;
use Nowo\TaskBoardBundle\Api\TaskApiCapability;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionConflictException;
use Nowo\TaskBoardBundle\Question\TaskQuestionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionInput;
use Nowo\TaskBoardBundle\Question\TaskQuestionQuery;
use Nowo\TaskBoardBundle\Question\TaskQuestionRefusedException;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function is_bool;
use function is_string;
use function preg_match;

/**
 * Since 2.23.0 (Beacon spec 242, `api.enabled` and `task_questions.enabled`): the questions of the Task API — list a
 * task's questions, ask, answer, withdraw, the answered questions of a board and the token user's open questions. Every
 * call goes through {@see TaskApiGuard} (reach, tenant, plan, level: reading needs `ReadOwn` / `ReadEverything`, the
 * writes `Comment`), then {@see TaskQuestionManager} (the person's right; one transaction, version-checked).
 *
 * A question out of reach, on another tenant or unknown is 404 `ACCESS_001` alike. A racing answer is 409 `CONFLICT_002`
 * with `current_version` (read it again; no `Retry-After`). Public: an in-process caller (the host's MCP server) uses it.
 */
final readonly class TaskApiQuestionOperations
{
    public const INVALID_QUESTION = 'QUESTION_001';

    public const INVALID_ANSWER = 'QUESTION_002';

    public const WITHDRAWN = 'QUESTION_003';

    public const FORBIDDEN = 'QUESTION_004';

    public const TASK_CLOSED = 'QUESTION_005';

    public function __construct(
        private TaskApiGuard $guard,
        private TaskApiAudit $audit,
        private TaskQuestionManager $manager,
        private TaskQuestionQuery $query,
    ) {
    }

    /**
     * @throws TaskApiRefusal
     *
     * @return array{0: Task, 1: list<TaskQuestion>}
     */
    public function taskQuestions(TaskApiPrincipal $principal, string $taskId, ?string $state): array
    {
        $task      = $this->guard->task($principal, $taskId, TaskApiCapability::ReadOwn);
        $wanted    = $this->state($state);
        $questions = array_values(array_filter($this->query->forTask($task), static fn (TaskQuestion $question): bool => !$wanted instanceof TaskQuestionState || $question->getState() === $wanted));

        return [$task, $questions];
    }

    /**
     * @throws TaskApiRefusal
     */
    public function ask(TaskApiPrincipal $principal, string $taskId, TaskApiInput $input): TaskQuestion
    {
        $this->audit->requireListener();
        $task = $this->guard->task($principal, $taskId, TaskApiCapability::ReadOwn);
        $this->guard->requireLevel($principal, TaskApiCapability::Comment);
        $options = [];
        foreach ($input->objects('options') as $option) {
            $label       = $option['label'] ?? null;
            $description = $option['description'] ?? null;
            $recommended = $option['recommended'] ?? false;
            if (!is_string($label) || ($description !== null && !is_string($description)) || !is_bool($recommended)) {
                throw new TaskApiRefusal(400, self::INVALID_QUESTION, 'Invalid question: options', ['field' => 'options']);
            }
            $options[] = new TaskQuestionOptionInput($label, $description, $recommended);
        }
        $choice = $input->string('choice') ?? TaskQuestionChoiceMode::Single->value;
        $mode   = TaskQuestionChoiceMode::tryFrom($choice) ?? throw new TaskApiRefusal(400, self::INVALID_QUESTION, 'Invalid question: choice', ['field' => 'choice']);
        $due    = $input->string('due_date');
        if ($due !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) !== 1) {
            throw new TaskApiRefusal(400, self::INVALID_QUESTION, 'Invalid question: due_date', ['field' => 'due_date']);
        }
        $dueOn    = $due === null ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', $due) ?: null);
        $question = $this->run(fn (): TaskQuestion => $this->manager->ask($principal->user, $task, new TaskQuestionInput(
            text: (string) $input->string('text', true),
            recipients: $input->strings('recipients'),
            options: $options,
            choiceMode: $mode,
            allowOther: $input->bool('allow_other') ?? false,
            context: $input->string('context'),
            dueOn: $dueOn,
        )));
        $this->audit->written($principal, 'question.create', $task->getBoard(), $task->getId(), ['question' => $question->getId()]);

        return $question;
    }

    /**
     * @throws TaskApiRefusal
     */
    public function answer(TaskApiPrincipal $principal, string $questionId, TaskApiInput $input): TaskQuestion
    {
        $this->audit->requireListener();
        $question = $this->reachable($principal, $questionId);
        $other    = $input->string('other_text');
        $version  = $input->int('version');
        $answer   = new TaskQuestionAnswerInput($input->strings('options'), $other);
        $via      = in_array($principal->channel, TaskQuestionAnswer::VIAS, true) && $principal->channel !== TaskQuestionAnswer::VIA_EXTERNAL ? $principal->channel : TaskApiPrincipal::CHANNEL_API;
        $this->run(fn (): TaskQuestionAnswer => $this->manager->answer($principal->user, $question, $answer, $version, $via));
        $this->audit->written($principal, 'question.answer', $question->getTask()->getBoard(), $question->getTask()->getId(), ['question' => $question->getId()]);

        return $question;
    }

    /**
     * @throws TaskApiRefusal
     */
    public function withdraw(TaskApiPrincipal $principal, string $questionId, TaskApiInput $input): TaskQuestion
    {
        $this->audit->requireListener();
        $question = $this->reachable($principal, $questionId);
        $reason   = (string) $input->string('reason', true);
        $version  = $input->int('version');
        $this->run(function () use ($principal, $question, $reason, $version): bool {
            $this->manager->withdraw($principal->user, $question, $reason, $version);

            return true;
        });
        $this->audit->written($principal, 'question.withdraw', $question->getTask()->getBoard(), $question->getTask()->getId(), ['question' => $question->getId()]);

        return $question;
    }

    /**
     * The answered questions of a board (`state=answered`, the only state served), keyset-paginated.
     *
     * @throws TaskApiRefusal
     *
     * @return array{0: TaskBoard, 1: list<TaskQuestionSnapshot>}
     */
    public function boardQuestions(TaskApiPrincipal $principal, string $listId, ?string $state, ?DateTimeImmutable $since, ?string $startId, int $limit): array
    {
        $board = $this->guard->board($principal, $listId, TaskApiCapability::ReadEverything);
        if ($state !== null && $state !== TaskQuestionState::Answered->value) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid state: only answered is listed per list', ['field' => 'state']);
        }

        return [$board, $this->query->answeredOnBoard($board->getId(), $since, $limit, $startId)];
    }

    /**
     * The token user's open questions (addressed to one of their subject ids) on tasks the token may open.
     *
     * @return list<TaskQuestion>
     */
    public function myQuestions(TaskApiPrincipal $principal, int $limit): array
    {
        $this->guard->requireLevel($principal, TaskApiCapability::ReadOwn);
        $open = $this->query->openForSubjects($this->manager->subjectIdsOf($principal->user, null));
        $mine = [];
        foreach ($open as $question) {
            if ($this->guard->canSeeTask($principal, $question->getTask())) {
                $mine[] = $question;
                if (count($mine) >= $limit) {
                    break;
                }
            }
        }

        return $mine;
    }

    /**
     * A question whose task the token may open, else 404 (unknown, out of reach, another tenant: alike).
     *
     * @throws TaskApiRefusal
     */
    private function reachable(TaskApiPrincipal $principal, string $questionId): TaskQuestion
    {
        $question = $this->manager->find($questionId);
        if (!$question instanceof TaskQuestion) {
            throw TaskApiRefusal::notFound();
        }
        try {
            $this->guard->task($principal, $question->getTask()->getId(), TaskApiCapability::ReadOwn);
        } catch (TaskApiRefusal) {
            throw TaskApiRefusal::notFound();
        }
        $this->guard->requireLevel($principal, TaskApiCapability::Comment);

        return $question;
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @throws TaskApiRefusal
     *
     * @return T
     */
    private function run(callable $write): mixed
    {
        try {
            return $write();
        } catch (TaskQuestionConflictException $conflict) {
            throw new TaskApiRefusal(409, TaskApiRefusal::CONFLICT_PARTIAL, $conflict->getMessage(), ['current_version' => $conflict->currentVersion]);
        } catch (TaskQuestionRefusedException $refused) {
            throw match ($refused->reason) {
                TaskQuestionRefusedException::INVALID_QUESTION => new TaskApiRefusal(400, self::INVALID_QUESTION, 'Invalid question: ' . $refused->field, ['field' => $refused->field]),
                TaskQuestionRefusedException::TASK_CLOSED      => new TaskApiRefusal(409, self::TASK_CLOSED, 'The task takes no more questions', ['field' => $refused->field]),
                TaskQuestionRefusedException::INVALID_ANSWER   => new TaskApiRefusal(400, self::INVALID_ANSWER, 'Invalid answer: ' . $refused->field, ['field' => $refused->field]),
                TaskQuestionRefusedException::WITHDRAWN        => new TaskApiRefusal(409, self::WITHDRAWN, 'The question is withdrawn'),
                default                                        => new TaskApiRefusal(403, self::FORBIDDEN, 'You may not do this on this question'),
            };
        }
    }

    private function state(?string $state): ?TaskQuestionState
    {
        if ($state === null || $state === '') {
            return null;
        }

        return TaskQuestionState::tryFrom($state) ?? throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid state', ['field' => 'state']);
    }
}
