<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question;

use DateTimeImmutable;
use Nowo\TaskBoardBundle\Dto\TaskQuestionAnswerFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionFormData;
use Nowo\TaskBoardBundle\Dto\TaskQuestionWithdrawFormData;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Form\CsrfActionType;
use Nowo\TaskBoardBundle\Form\TaskQuestionAnswerFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionFormType;
use Nowo\TaskBoardBundle\Form\TaskQuestionWithdrawFormType;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestion;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionAnswer;
use Nowo\TaskBoardBundle\Question\Entity\TaskQuestionRecipient;
use Nowo\TaskBoardBundle\Security\TaskBoardResourceAccessChecker;
use Nowo\TaskBoardBundle\Support\ActorId;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenant;
use Nowo\TaskBoardBundle\Tenancy\TaskBoardTenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function parse_url;
use function str_replace;
use function strtolower;
use function strval;

use const PHP_URL_SCHEME;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.enabled`): what the pages show of the questions — the *Questions* section
 * of a task (cards, the ask form, the answer, withdraw and publish forms the viewer may use), the open counts of a page of
 * tasks and the viewer's open questions across the boards they may see. Every form posts to a route that checks the
 * right again on the server.
 *
 * Stateless (worker mode): every call reads what it needs; nothing is kept for later calls.
 */
final readonly class TaskQuestionView implements TaskQuestionCountsInterface
{
    /** The tabs of *my open questions*. */
    public const TAB_TO_ANSWER = 'to_answer';

    public const TAB_ASKED = 'asked';

    /** Since 2.31.0: the question dialogs, each the GET of its write route. */
    public const ACTION_ANSWER = 'answer';

    public const ACTION_WITHDRAW = 'withdraw';

    public const ACTION_PUBLISH = 'publish';

    public const DIALOG_ACTIONS = [self::ACTION_ANSWER, self::ACTION_WITHDRAW, self::ACTION_PUBLISH];

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
     * Since 2.31.0 (owner rule 2026-11-23, every dialog loads its content on demand) a card carries the URLs of its answer,
     * withdraw and publish dialogs (`answerUrl`, `withdrawUrl`, `publishUrl`: the GET of their write routes) and no
     * forms; `$withForms` true builds the 2.30 forms per card (`answerForm`, `withdrawForm`, `publishForm`) for a host
     * template that still renders them (deprecated, removed in 3.0).
     *
     * @param array<string, string> $routeExtra extra route parameters of the page (`_target_path` of a drawer)
     *
     * @return array{cards: list<array<string, mixed>>, askForm: FormView|null, open: int, total: int}|null
     */
    public function section(Task $task, array $routeExtra = [], bool $withForms = false): ?array
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
            $cards[] = $this->card($user, $question, $subjects, $today, $routeExtra, $withForms);
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

            return $this->tenantCounts($task->getBoard()->getTenantKey(), $user, $context, $tenant, $tenants) && $this->access->canViewTask($user, $task);
        }));

        return $limit === null ? $visible : array_slice($visible, 0, $limit);
    }

    /**
     * Since 2.32.0 (Beacon request, a menu badge on every page): how many rows {@see self::mine()} lists in the
     * `to_answer` tab, without reading them. The same rules — the viewer's subject ids, the tenant rule (deny by default),
     * {@see TaskBoardResourceAccessChecker::canViewTask()} — in a constant number of queries whatever the number of
     * questions: no question is read, no task access is checked one by one and no URL is built.
     *
     * 1. One query reads the boards of the viewer's open questions; each passes the tenant rule and the board rule
     *    ({@see TaskBoardResourceAccessChecker::boardViewDecider()}: BOARD_ACCESS_CHECK per board, the viewer's teams read
     *    once).
     * 2. Without a TASK_ACCESS_CHECK listener, one query counts the open questions on the boards the viewer may see.
     * 3. On the other boards (on every board with such a listener), one query reads the candidate tasks with their
     *    members and each passes {@see TaskBoardResourceAccessChecker::taskViewDecider()} — TASK_ACCESS_CHECK, then the
     *    board, then the participants compared exactly in PHP, as in `mine()` (audit ZAK-1) — then one query counts on
     *    the visible tasks.
     *
     * A subject id is compared byte for byte (REQ-SEC-006). Unlike the list, the count is not cut at
     * {@see TaskQuestionQuery::MY_QUESTIONS_CAP} candidates. 0 without a signed-in viewer, and on a tenant URL whose
     * tenant does not resolve for them. Stateless (worker mode): nothing is kept between calls.
     */
    public function countToAnswer(): int
    {
        $user = $this->user();
        if (!$user instanceof UserInterface) {
            return 0;
        }
        $context = $this->tenantContext?->isEnabled() === true ? $this->tenantContext : null;
        $tenant  = $context?->current($user);
        if ($context instanceof TaskBoardTenantContext && $context->isTenantRequest() && !$tenant instanceof TaskBoardTenant) {
            return 0;
        }
        $subjects = $this->manager->subjectIdsOf($user, $tenant?->key);
        if ($subjects === []) {
            return 0;
        }
        $boardViewable = $this->access->boardViewDecider($user);
        /** @var array<string, bool> $tenants the decision per tenant key, for this call only */
        $tenants = [];
        /** @var array<string, bool> $boards board id => whether the viewer may see the board (only boards whose tenant counts) */
        $boards = [];
        foreach ($this->query->boardsOpenForSubjects($subjects, $tenant?->key) as $board) {
            if ($this->tenantCounts($board->getTenantKey(), $user, $context, $tenant, $tenants)) {
                $boards[$board->getId()] = $boardViewable($board);
            }
        }
        if ($boards === []) {
            return 0;
        }
        // Boards the viewer may see whole: counted in SQL when no TASK_ACCESS_CHECK listener may decide a task.
        $whole = $this->access->taskListenersMayDecide() ? [] : array_map(strval(...), array_keys(array_filter($boards)));
        $count = $this->query->countOpenForSubjects($subjects, $whole);
        // Every other board (all of them with a listener): its candidate tasks in one query, each decided in PHP exactly as
        // canViewTask() decides it (TASK_ACCESS_CHECK, then the board, then the participants compared byte for byte; audit
        // ZAK-1), then one count on the visible ones.
        $rest = array_values(array_diff(array_map(strval(...), array_keys($boards)), $whole));
        if ($rest === []) {
            return $count;
        }
        $taskViewable = $this->access->taskViewDecider($user, static fn (TaskBoard $board): bool => $boards[$board->getId()] ?? false);
        $tasks        = [];
        foreach ($this->query->tasksOpenForSubjects($subjects, $rest) as $task) {
            if ($taskViewable($task)) {
                $tasks[] = $task->getId();
            }
        }

        return $count + $this->query->countOpenOnTasks($subjects, $tasks);
    }

    /**
     * The tenant rule of *my open questions* for a board's tenant key: with a resolved request tenant, that tenant's key
     * exactly; with tenancy outside a tenant URL, a key the resolver opens to the viewer (asked once per key and call,
     * `$tenants`); without tenancy, every board.
     *
     * @param array<string, bool> $tenants
     */
    private function tenantCounts(?string $key, UserInterface $user, ?TaskBoardTenantContext $context, ?TaskBoardTenant $tenant, array &$tenants): bool
    {
        if ($tenant instanceof TaskBoardTenant) {
            return $key === $tenant->key;
        }
        if (!$context instanceof TaskBoardTenantContext) {
            return true;
        }
        if ($key === null) {
            return false;
        }

        return $tenants[$key] ??= $context->tenantOfKey($key, $user) instanceof TaskBoardTenant;
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
    private function card(UserInterface $user, TaskQuestion $question, array $subjects, DateTimeImmutable $today, array $routeExtra, bool $withForms = false): array
    {
        $task    = $question->getTask();
        $params  = ['taskId' => $task->getId(), 'questionId' => $question->getId()] + $routeExtra;
        $manages = $this->access->canManageBoard($user, $task->getBoard());
        $asker   = ActorId::of($user) !== null && ActorId::of($user) === ActorId::of($question->getAskedBy());
        $allowed = [];
        foreach (self::DIALOG_ACTIONS as $action) {
            $allowed[$action] = $this->allows($user, $action, $question, $subjects);
        }
        $form = fn (string $action): ?FormView => $withForms && $allowed[$action] ? $this->buildForm($action, $question, $routeExtra)->createView() : null;
        // The answer and withdraw dialogs carry the version this card shows: a write after a newer one is still refused,
        // however late the dialog was opened (REQ-RUNTIME-003).
        $url = fn (string $action): ?string => $allowed[$action] ? $this->url('task_question_' . $action, $params + ($action === self::ACTION_PUBLISH ? [] : ['version' => (string) $question->getVersion()])) : null;

        return [
            'question'     => $question,
            'answer'       => $question->getCurrentAnswer(),
            'superseded'   => array_values(array_filter($question->getAnswers(), static fn (TaskQuestionAnswer $a): bool => $a->isSuperseded())),
            'overdue'      => $question->isOverdue($today),
            'answerForm'   => $form(self::ACTION_ANSWER),
            'withdrawForm' => $form(self::ACTION_WITHDRAW),
            'publishForm'  => $form(self::ACTION_PUBLISH),
            // Since 2.31.0: the on-demand dialogs (null when the viewer may not use them).
            'answerUrl'    => $url(self::ACTION_ANSWER),
            'withdrawUrl'  => $url(self::ACTION_WITHDRAW),
            'publishUrl'   => $url(self::ACTION_PUBLISH),
            'publishLabel' => $allowed[self::ACTION_PUBLISH] ? $this->manager->publishLabel($user, $question) : null,
            'answerLinks'  => ($asker || $manages) && !$question->isWithdrawn() ? $this->answerLinks($question) : [],
            'mine'         => array_values(array_filter($subjects, static fn (string $subject): bool => in_array($subject, array_map(static fn (TaskQuestionRecipient $r): string => $r->getSubjectId(), $question->getRecipients()), true))) !== [],
        ];
    }

    /**
     * Since 2.31.0: the form of one question dialog (`answer`, `withdraw`, `publish`) for the signed-in viewer, posting
     * to its write route; null when the viewer may not use it (the same decision as the card's: the write checks again).
     * `$version`: the version the page showed (the opener's `version`), else the current one.
     *
     * @param array<string, string> $routeExtra
     */
    // @phpstan-ignore missingType.generics (Rector drops a `FormInterface<mixed>|null` docblock as a useless union)
    public function dialogForm(string $action, TaskQuestion $question, array $routeExtra = [], ?int $version = null): ?FormInterface
    {
        $user = $this->user();
        if (!$user instanceof UserInterface || !in_array($action, self::DIALOG_ACTIONS, true)) {
            return null;
        }
        $subjects = $this->manager->subjectIdsOf($user, $question->getTask()->getBoard()->getTenantKey());

        return $this->allows($user, $action, $question, $subjects) ? $this->buildForm($action, $question, $routeExtra, $version) : null;
    }

    /**
     * Whether the viewer may use a question action (the card shows it).
     *
     * @param list<string> $subjects
     */
    private function allows(UserInterface $user, string $action, TaskQuestion $question, array $subjects): bool
    {
        return match ($action) {
            self::ACTION_ANSWER   => !$question->isWithdrawn() && $this->access->canAnswerQuestion($user, $question, $subjects),
            self::ACTION_WITHDRAW => !$question->isWithdrawn() && $this->access->canWithdrawQuestion($user, $question),
            default               => $question->isAnswered() && $this->manager->publishLabel($user, $question) !== null,
        };
    }

    /**
     * @param array<string, string> $routeExtra
     *
     * @return FormInterface<mixed>
     */
    private function buildForm(string $action, TaskQuestion $question, array $routeExtra, ?int $version = null): FormInterface
    {
        $version = (string) ($version ?? $question->getVersion());
        $params  = ['taskId' => $question->getTask()->getId(), 'questionId' => $question->getId()] + $routeExtra;
        $name    = str_replace('-', '', $question->getId());
        if ($action === self::ACTION_ANSWER) {
            $current = $question->getCurrentAnswer();
            $data    = new TaskQuestionAnswerFormData(
                $current instanceof TaskQuestionAnswer ? ($question->isMultiple() ? $current->getSelectedOptionIds() : ($current->getSelectedOptionIds()[0] ?? null)) : null,
                $current?->getOtherText(),
                $version,
            );

            return $this->forms->createNamed('qa_' . $name, TaskQuestionAnswerFormType::class, $data, [
                'question'      => $question,
                'csrf_token_id' => self::csrfId('answer', $question->getId()),
                'action'        => $this->url('task_question_answer', $params),
            ]);
        }
        if ($action === self::ACTION_WITHDRAW) {
            return $this->forms->createNamed('qw_' . $name, TaskQuestionWithdrawFormType::class, new TaskQuestionWithdrawFormData('', $version), [
                'csrf_token_id' => self::csrfId('withdraw', $question->getId()),
                'action'        => $this->url('task_question_withdraw', $params),
            ]);
        }

        return $this->forms->createNamed('qp_' . $name, CsrfActionType::class, null, [
            'csrf_token_id' => self::csrfId('publish', $question->getId()),
            'action'        => $this->url('task_question_publish', $params),
        ]);
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
