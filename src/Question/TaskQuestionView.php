<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskQuestionAnswerFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionWithdrawFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskQuestionAnswerFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionWithdrawFormType;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function parse_url;
use function str_replace;
use function strtolower;

use const PHP_URL_SCHEME;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.enabled`): what the pages show of the questions — the *Questions* section
 * of a task (cards, the ask form, the answer, withdraw and publish forms the viewer may use), the open counts of a page of
 * tasks and the viewer's open questions across the boards they may see. Every form posts to a route that checks the
 * right again on the server.
 *
 * Stateless (worker mode): every call reads what it needs; nothing is kept for later calls.
 */
final readonly class TaskQuestionView
{
    /** The tabs of *my open questions*. */
    public const TAB_TO_ANSWER = 'to_answer';

    public const TAB_ASKED = 'asked';

    /** The CSRF token id of a question form: `task_board_question_ask`, or `task_board_question_<action>_<question id>`. */
    public static function csrfId(string $action, ?string $questionId = null): string
    {
        return 'task_board_question_' . $action . ($questionId === null ? '' : '_' . $questionId);
    }

    /**
     * @param array<string, array{path?: string, name?: string}> $routes the `routes` configuration
     */
    public function __construct(
        private TaskQuestionManager $manager,
        private TaskQuestionQuery $query,
        private TaskBoardResourceAccessChecker $access,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private array $routes = [],
        private ?TokenStorageInterface $tokenStorage = null,
        private ?TaskQuestionExternalAnswerInterface $externalAnswers = null,
        private ?TaskBoardTenantContext $tenantContext = null,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * The *Questions* section of `$task` for the signed-in viewer; null without one, or (since 2.23.1) when they may not
     * see the task or, with tenancy, open its tenant.
     *
     * @param array<string, string> $routeExtra extra route parameters of the page (`_target_path` of a drawer)
     *
     * @return array{cards: list<array<string, mixed>>, askForm: FormView|null, open: int, total: int}|null
     */
    public function section(Task $task, array $routeExtra = []): ?array
    {
        $user = $this->user();
        // Since 2.23.1: a viewer who may not see the task, or (with tenancy) whose tenant is not one they may open, gets no
        // section, whoever renders it (a host template too).
        if (!$user instanceof UserInterface || !$this->inOpenTenant($user, $task) || !$this->access->canViewTask($user, $task)) {
            return null;
        }
        $questions = $this->query->forTask($task);
        $subjects  = $this->manager->subjectIdsOf($user, $task->getBoard()->getTenantKey());
        $today     = $this->clock->now();
        $cards     = [];
        foreach ($questions as $question) {
            $cards[] = $this->card($user, $question, $subjects, $today, $routeExtra);
        }
        $askForm = null;
        if ($this->access->canAskQuestion($user, $task)) {
            $askForm = $this->forms->createNamed('task_question', TaskQuestionFormType::class, new TaskQuestionFormData(), [
                'recipients'    => $this->manager->candidates($user, $task),
                'csrf_token_id' => self::csrfId('ask'),
                'action'        => $this->url('task_question_ask', ['taskId' => $task->getId()] + $routeExtra),
            ])->createView();
        }

        return [
            'cards'   => $cards,
            'askForm' => $askForm,
            'open'    => count(array_filter($questions, static fn (TaskQuestion $question): bool => $question->isOpen())),
            'total'   => count($questions),
        ];
    }

    /**
     * How many open questions each task of a page has (one query per 500 tasks; tasks with none are left out).
     *
     * @param iterable<Task> $tasks
     *
     * @return array<string, int>
     */
    public function openCounts(iterable $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = $task->getId();
        }

        return $ids === [] ? [] : $this->query->openCounts($ids);
    }

    /**
     * The viewer's open questions (`to_answer`: addressed to one of their subject ids; `asked`: asked by them) on tasks
     * they may see — inside a tenant URL that tenant's only — re-checked row by row, so a row the viewer may not see never
     * counts.
     *
     * Since 2.23.1 (Beacon request, audit UG; 2.23.0 widened to every board): with tenancy, deny by default.
     * - A request that names a tenant ({@see TaskBoardTenantContext::isTenantRequest()}) which does not resolve for the
     *   viewer (unknown, or one the resolver does not open to them) gives no rows; the page answers 404 before asking.
     * - A request whose tenant resolves lists that tenant's rows only.
     * - Outside a tenant URL, a row counts only when its board's tenant is one the resolver opens to the viewer
     *   ({@see TaskBoardTenantContext::tenantOfKey()}, the Task API's rule); a board without a tenant never counts.
     * - Every row also needs the view access of its task ({@see TaskBoardResourceAccessChecker::canViewTask()}).
     *   The board-list decision (`canListBoards()`) is not asked: a user who may open a shared board of a tenant whose
     *   board list they may not see (a sharee) still sees their questions there. TASK_QUESTION_ACCESS_CHECK is not asked
     *   either: it decides the question actions, never what is listed.
     *
     * @return list<TaskQuestion>
     */
    public function mine(string $tab = self::TAB_TO_ANSWER, ?int $limit = null): array
    {
        $user = $this->user();
        if (!$user instanceof UserInterface) {
            return [];
        }
        $context = $this->tenantContext?->isEnabled() === true ? $this->tenantContext : null;
        $tenant  = $context?->current($user);
        if ($context instanceof TaskBoardTenantContext && $context->isTenantRequest() && !$tenant instanceof TaskBoardTenant) {
            return [];
        }
        $candidates = $tab === self::TAB_ASKED
            ? $this->query->openAskedBy($user)
            : $this->query->openForSubjects($this->manager->subjectIdsOf($user, $tenant?->key));
        /** @var array<string, bool> $tenants the decision per tenant key, for this call only */
        $tenants = [];
        $visible = array_values(array_filter($candidates, function (TaskQuestion $question) use ($user, $context, $tenant, &$tenants): bool {
            $task = $question->getTask();
            $key  = $task->getBoard()->getTenantKey();
            if ($tenant instanceof TaskBoardTenant) {
                if ($key !== $tenant->key) {
                    return false;
                }
            } elseif ($context instanceof TaskBoardTenantContext) {
                if ($key === null) {
                    return false;
                }
                $tenants[$key] ??= $context->tenantOfKey($key, $user) instanceof TaskBoardTenant;
                if (!$tenants[$key]) {
                    return false;
                }
            }

            return $this->access->canViewTask($user, $task);
        }));

        return $limit === null ? $visible : array_slice($visible, 0, $limit);
    }

    /**
     * Since 2.23.1 (audit UG-3): with tenancy, whether the task's tenant is one the viewer may open — the request's own
     * tenant, or one the resolver opens to them by key. A board without a tenant is in none.
     */
    private function inOpenTenant(UserInterface $user, Task $task): bool
    {
        $context = $this->tenantContext;
        if ($context?->isEnabled() !== true) {
            return true;
        }
        $key = $task->getBoard()->getTenantKey();
        if ($key === null) {
            return false;
        }

        return $context->current($user)?->key === $key || $context->tenantOfKey($key, $user) instanceof TaskBoardTenant;
    }

    /**
     * @param list<string> $subjects
     * @param array<string, string> $routeExtra
     *
     * @return array<string, mixed>
     */
    private function card(UserInterface $user, TaskQuestion $question, array $subjects, DateTimeImmutable $today, array $routeExtra): array
    {
        $task   = $question->getTask();
        $params = ['taskId' => $task->getId(), 'questionId' => $question->getId()] + $routeExtra;
        $name   = str_replace('-', '', $question->getId());
        $answer = null;
        if (!$question->isWithdrawn() && $this->access->canAnswerQuestion($user, $question, $subjects)) {
            $current = $question->getCurrentAnswer();
            $data    = new TaskQuestionAnswerFormData(
                $current === null ? null : ($question->isMultiple() ? $current->getSelectedOptionIds() : ($current->getSelectedOptionIds()[0] ?? null)),
                $current?->getOtherText(),
                (string) $question->getVersion(),
            );
            $answer = $this->forms->createNamed('qa_' . $name, TaskQuestionAnswerFormType::class, $data, [
                'question'      => $question,
                'csrf_token_id' => self::csrfId('answer', $question->getId()),
                'action'        => $this->url('task_question_answer', $params),
            ])->createView();
        }
        $withdraw = null;
        $manages  = $this->access->canManageBoard($user, $task->getBoard());
        $asker    = ActorId::of($user) !== null && ActorId::of($user) === ActorId::of($question->getAskedBy());
        if (!$question->isWithdrawn() && $this->access->canWithdrawQuestion($user, $question)) {
            $withdraw = $this->forms->createNamed('qw_' . $name, TaskQuestionWithdrawFormType::class, new TaskQuestionWithdrawFormData('', (string) $question->getVersion()), [
                'csrf_token_id' => self::csrfId('withdraw', $question->getId()),
                'action'        => $this->url('task_question_withdraw', $params),
            ])->createView();
        }
        $publishLabel = $question->isAnswered() ? $this->manager->publishLabel($user, $question) : null;
        $publish      = $publishLabel === null ? null : $this->forms->createNamed('qp_' . $name, CsrfActionType::class, null, [
            'csrf_token_id' => self::csrfId('publish', $question->getId()),
            'action'        => $this->url('task_question_publish', $params),
        ])->createView();

        return [
            'question'     => $question,
            'answer'       => $question->getCurrentAnswer(),
            'superseded'   => array_values(array_filter($question->getAnswers(), static fn ($a): bool => $a->isSuperseded())),
            'overdue'      => $question->isOverdue($today),
            'answerForm'   => $answer,
            'withdrawForm' => $withdraw,
            'publishForm'  => $publish,
            'publishLabel' => $publishLabel,
            'answerLinks'  => ($asker || $manages) && !$question->isWithdrawn() ? $this->answerLinks($question) : [],
            'mine'         => array_values(array_filter($subjects, static fn (string $subject): bool => in_array($subject, array_map(static fn ($r): string => $r->getSubjectId(), $question->getRecipients()), true))) !== [],
        ];
    }

    /**
     * The host's signed links of the question's external recipients (http / https only; never stored or logged).
     *
     * @return array<string, string> subject id => URL
     */
    private function answerLinks(TaskQuestion $question): array
    {
        if (!$this->externalAnswers instanceof TaskQuestionExternalAnswerInterface) {
            return [];
        }
        $snapshot = TaskQuestionSnapshot::of($question);
        $links    = [];
        foreach ($question->getRecipients() as $recipient) {
            if (!$recipient->isExternal()) {
                continue;
            }
            $url = $this->externalAnswers->answerUrl($snapshot, $recipient->getSubjectId());
            if ($url !== null && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $links[$recipient->getSubjectId()] = $url;
            }
        }

        return $links;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function url(string $key, array $parameters): string
    {
        return $this->urls->generate($this->routes[$key]['name'] ?? 'nowo_task_board_' . $key, $parameters);
    }

    private function user(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
