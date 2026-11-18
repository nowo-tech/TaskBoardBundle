<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api\Controller;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiQuestionOperations;
use Nowo\TaskBoardBundle\Api\Security\TaskApiActors;
use Nowo\TaskBoardBundle\Api\Security\TaskApiIdempotencyListener;
use Nowo\TaskBoardBundle\Api\Security\TaskApiRequestListener;
use Nowo\TaskBoardBundle\Api\TaskApiInput;
use Nowo\TaskBoardBundle\Api\TaskApiPrincipal;
use Nowo\TaskBoardBundle\Api\TaskApiRefusal;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\TaskQuestionAnswerSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionOptionSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionRecipientSnapshot;
use Nowo\TaskBoardBundle\Question\TaskQuestionSnapshot;
use Nowo\TaskBoardBundle\Routing\TaskBoardRouteLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;
use function array_reverse;
use function count;
use function intdiv;
use function is_string;
use function preg_match;

/**
 * Since 2.23.0 (Beacon spec 242): the question routes of the Task API, registered with `api.enabled` and
 * `task_questions.enabled`. **Not in ClickUp's API.** Thin: one operation each, then the question's JSON
 * ({@see self::question()}). Markdown context comes back as stored, never as HTML.
 */
#[Route(requirements: self::REQUIREMENTS, defaults: TaskBoardRouteLoader::API_DEFAULTS)]
final readonly class TaskApiQuestionController
{
    /** The API placeholders plus `{questionId}`. */
    public const REQUIREMENTS = TaskBoardRouteLoader::API_REQUIREMENTS + ['questionId' => '[^/]+'];

    /** The most answered questions of one page of a list. */
    public const LIST_PAGE = 100;

    public function __construct(
        private TaskApiQuestionOperations $operations,
        private int $maxBodyBytes = 1048576,
        // Since 2.22.0's write-unit replay rule: the principal's user as the manager manages it now, per attempt.
        private ?TaskApiActors $actors = null,
    ) {
    }

    #[Route('/v2/task/{taskId}/question', name: 'nowo_task_board_api_task_questions', methods: ['GET'])]
    public function taskQuestions(Request $request, string $taskId): JsonResponse
    {
        $state         = $request->query->get('state');
        [, $questions] = $this->operations->taskQuestions(TaskApiRequestListener::principalOf($request), $taskId, is_string($state) ? $state : null);

        return new JsonResponse(['questions' => array_map(static fn (TaskQuestion $question): array => self::question(TaskQuestionSnapshot::of($question)), $questions)]);
    }

    #[Route('/v2/task/{taskId}/question', name: 'nowo_task_board_api_question_ask', methods: ['POST'])]
    public function ask(Request $request, string $taskId): JsonResponse
    {
        $input    = TaskApiInput::fromRequest($request, $this->maxBodyBytes);
        $question = $this->operations->ask($this->principal($request), $taskId, $input);

        return $this->written($request, $question, $input);
    }

    #[Route('/v2/question/{questionId}/answer', name: 'nowo_task_board_api_question_answer', methods: ['POST'])]
    public function answer(Request $request, string $questionId): JsonResponse
    {
        $input    = TaskApiInput::fromRequest($request, $this->maxBodyBytes);
        $question = $this->operations->answer($this->principal($request), $questionId, $input);

        return $this->written($request, $question, $input);
    }

    #[Route('/v2/question/{questionId}/withdraw', name: 'nowo_task_board_api_question_withdraw', methods: ['POST'])]
    public function withdraw(Request $request, string $questionId): JsonResponse
    {
        $input    = TaskApiInput::fromRequest($request, $this->maxBodyBytes);
        $question = $this->operations->withdraw($this->principal($request), $questionId, $input);

        return $this->written($request, $question, $input);
    }

    #[Route('/v2/list/{listId}/question', name: 'nowo_task_board_api_list_questions', methods: ['GET'])]
    public function listQuestions(Request $request, string $listId): JsonResponse
    {
        $state = $request->query->get('state');
        $since = $request->query->get('answered_since');
        if ($since !== null && (!is_string($since) || preg_match('/^\d{1,15}$/D', $since) !== 1)) {
            throw new TaskApiRefusal(400, 'INPUT_002', 'Invalid answered_since: Unix milliseconds', ['field' => 'answered_since']);
        }
        $start         = $request->query->get('start_id');
        [, $questions] = $this->operations->boardQuestions(
            TaskApiRequestListener::principalOf($request),
            $listId,
            is_string($state) ? $state : null,
            $since === null ? null : new DateTimeImmutable('@' . intdiv((int) $since, 1000)),
            is_string($start) && $start !== '' ? $start : null,
            self::LIST_PAGE,
        );

        return new JsonResponse(['questions' => array_map(self::question(...), $questions), 'last_page' => count($questions) < self::LIST_PAGE]);
    }

    #[Route('/v2/user/question', name: 'nowo_task_board_api_my_questions', methods: ['GET'])]
    public function myQuestions(Request $request): JsonResponse
    {
        $questions = $this->operations->myQuestions(TaskApiRequestListener::principalOf($request), self::LIST_PAGE);

        return new JsonResponse(['questions' => array_map(static fn (TaskQuestion $question): array => self::question(TaskQuestionSnapshot::of($question)), $questions)]);
    }

    /**
     * The JSON of a question: values only, dates in Unix milliseconds.
     *
     * @return array<string, mixed>
     */
    public static function question(TaskQuestionSnapshot $question): array
    {
        return [
            'id'          => $question->id,
            'task_id'     => $question->taskId,
            'list_id'     => $question->boardId,
            'text'        => $question->text,
            'context'     => $question->context,
            'choice'      => $question->choiceMode->value,
            'allow_other' => $question->allowOther,
            'state'       => $question->state->value,
            'options'     => array_map(static fn (TaskQuestionOptionSnapshot $option): array => ['id' => $option->id, 'label' => $option->label, 'description' => $option->description, 'recommended' => $option->recommended], $question->options),
            'recipients'  => array_map(static fn (TaskQuestionRecipientSnapshot $recipient): array => ['id' => $recipient->subjectId, 'label' => $recipient->label, 'external' => $recipient->external], $question->recipients),
            'due_date'    => $question->dueOn?->format('Y-m-d'),
            'asked_by'    => $question->askedById === null && $question->askedByName === null ? null : ['id' => $question->askedById, 'name' => $question->askedByName],
            'asked_at'    => self::ms($question->askedAt),
            'answer'      => $question->currentAnswer === null ? null : self::answerOf($question->currentAnswer),
            'answers'     => array_map(self::answerOf(...), array_reverse($question->answers)),
            'withdrawn'   => $question->withdrawnAt === null ? null : ['reason' => $question->withdrawReason, 'by' => $question->withdrawnById === null && $question->withdrawnByName === null ? null : ['id' => $question->withdrawnById, 'name' => $question->withdrawnByName], 'at' => self::ms($question->withdrawnAt)],
            'published'   => $question->publishedUrl === null ? null : ['url' => $question->publishedUrl, 'label' => $question->publishedLabel, 'at' => $question->publishedAt === null ? null : self::ms($question->publishedAt)],
            'version'     => $question->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function answerOf(TaskQuestionAnswerSnapshot $answer): array
    {
        return [
            'id'            => $answer->id,
            'options'       => $answer->optionIds,
            'option_labels' => $answer->optionLabels,
            'other_text'    => $answer->otherText,
            'subject_id'    => $answer->subjectId,
            'answered_by'   => $answer->answeredById === null && $answer->answeredByName === null ? null : ['id' => $answer->answeredById, 'name' => $answer->answeredByName],
            'via'           => $answer->via,
            'answered_at'   => self::ms($answer->answeredAt),
            'superseded_at' => $answer->supersededAt === null ? null : self::ms($answer->supersededAt),
        ];
    }

    private static function ms(DateTimeImmutable $at): int
    {
        return $at->getTimestamp() * 1000;
    }

    /** The request's principal with its user managed by the bundle's manager (a write unit may have reset it). */
    private function principal(Request $request): TaskApiPrincipal
    {
        return $this->actors?->principal($request) ?? TaskApiRequestListener::principalOf($request);
    }

    private function written(Request $request, TaskQuestion $question, TaskApiInput $input): JsonResponse
    {
        TaskApiIdempotencyListener::target($request, $question->getTask()->getBoard());
        $data     = self::question(TaskQuestionSnapshot::of($question));
        $warnings = $input->warnings();
        if ($warnings !== []) {
            $data['warnings'] = $warnings;
        }

        return new JsonResponse($data);
    }
}
