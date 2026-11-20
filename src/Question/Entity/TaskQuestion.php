<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Question\TaskQuestionChoiceMode;
use Nowo\TaskBoardBundle\Question\TaskQuestionManager;
use Nowo\TaskBoardBundle\Question\TaskQuestionState;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Support\UserLabel;
use Nowo\TaskBoardBundle\ValueObject\Uuid;
use SortDirection;

use function array_values;

/**
 * Since 2.23.0 (Beacon spec 242, `task_questions.enabled`): a question asked on a task for the business to answer. It
 * has a text, an optional Markdown context, 0 or 2–4 options (one may be recommended), single or multiple choice, an
 * optional *Other* free text, one or more recipients (opaque subject ids of the host) and an optional due date.
 *
 * Its board is always its task's (no copy), so a moved task takes its questions along; gone with its task
 * (`ON DELETE CASCADE`). `version` grows by one on every write after the ask (answer, supersede, withdraw, publish),
 * under the row lock {@see TaskQuestionManager} takes: a plain column, never Doctrine's
 * `#[Version]` (whose conflict would close the entity manager). The options never change once asked; answers are never
 * edited or deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_task_board_task_questions_task_state', columns: ['task_id', 'state'])]
#[ORM\Index(name: 'idx_task_board_task_questions_state_due', columns: ['state', 'due_on'])]
class TaskQuestion
{
    public const TABLE = 'task_board_task_questions';

    /** The longest text. */
    public const TEXT_MAX = 500;

    /** The longest Markdown context, in characters. */
    public const CONTEXT_MAX = 20000;

    /** The longest withdraw reason. */
    public const REASON_MAX = 500;

    /** The longest published label. */
    public const PUBLISHED_LABEL_MAX = 180;

    /** The longest published URL. */
    public const PUBLISHED_URL_MAX = 2048;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 10, enumType: TaskQuestionState::class)]
    private TaskQuestionState $state = TaskQuestionState::Open;

    #[ORM\Column(name: 'asked_by_name', type: 'string', length: 180, nullable: true)]
    private ?string $askedByName;

    #[ORM\Column(name: 'current_answer_id', type: 'string', length: 36, nullable: true)]
    private ?string $currentAnswerId = null;

    #[ORM\Column(name: 'answered_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $answeredAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'withdrawn_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $withdrawnBy = null;

    #[ORM\Column(name: 'withdrawn_by_name', type: 'string', length: 180, nullable: true)]
    private ?string $withdrawnByName = null;

    #[ORM\Column(name: 'withdrawn_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column(name: 'withdraw_reason', type: 'string', length: 500, nullable: true)]
    private ?string $withdrawReason = null;

    #[ORM\Column(name: 'published_url', type: 'string', length: 2048, nullable: true)]
    private ?string $publishedUrl = null;

    #[ORM\Column(name: 'published_label', type: 'string', length: 180, nullable: true)]
    private ?string $publishedLabel = null;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $version = 1;

    /** @var Collection<int, TaskQuestionOption> */
    #[ORM\OneToMany(targetEntity: TaskQuestionOption::class, mappedBy: 'question', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    private Collection $options;

    /** @var Collection<int, TaskQuestionRecipient> */
    #[ORM\OneToMany(targetEntity: TaskQuestionRecipient::class, mappedBy: 'question', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => SortDirection::Ascending])]
    private Collection $recipients;

    /** @var Collection<int, TaskQuestionAnswer> */
    #[ORM\OneToMany(targetEntity: TaskQuestionAnswer::class, mappedBy: 'question', cascade: ['persist'])]
    #[ORM\OrderBy(['sequence' => SortDirection::Ascending])]
    private Collection $answers;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\Column(name: 'question_text', type: 'string', length: 500)]
        private string $text,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $context,
        #[ORM\Column(name: 'choice_mode', type: 'string', length: 8, enumType: TaskQuestionChoiceMode::class)]
        private TaskQuestionChoiceMode $choiceMode,
        #[ORM\Column(name: 'allow_other', type: 'boolean')]
        private bool $allowOther,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'asked_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $askedBy,
        #[ORM\Column(name: 'asked_at', type: 'datetime_immutable')]
        private DateTimeImmutable $askedAt,
        #[ORM\Column(name: 'due_on', type: 'date_immutable', nullable: true)]
        private ?DateTimeImmutable $dueOn = null,
    ) {
        $this->id          = Uuid::generate()->toString();
        $this->askedByName = $askedBy === null ? null : Text::cut(UserLabel::of($askedBy), Task::USER_NAME_MAX);
        $this->options     = new ArrayCollection();
        $this->recipients  = new ArrayCollection();
        $this->answers     = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getChoiceMode(): TaskQuestionChoiceMode
    {
        return $this->choiceMode;
    }

    public function isMultiple(): bool
    {
        return $this->choiceMode === TaskQuestionChoiceMode::Multiple;
    }

    public function allowsOther(): bool
    {
        return $this->allowOther;
    }

    public function getState(): TaskQuestionState
    {
        return $this->state;
    }

    public function isOpen(): bool
    {
        return $this->state === TaskQuestionState::Open;
    }

    public function isAnswered(): bool
    {
        return $this->state === TaskQuestionState::Answered;
    }

    public function isWithdrawn(): bool
    {
        return $this->state === TaskQuestionState::Withdrawn;
    }

    /** Null once the account is gone; {@see self::getAskedByName()} keeps the name unless it was forgotten. */
    public function getAskedBy(): ?object
    {
        return $this->askedBy;
    }

    public function getAskedByName(): ?string
    {
        return $this->askedByName;
    }

    public function getAskedAt(): DateTimeImmutable
    {
        return $this->askedAt;
    }

    public function getDueOn(): ?DateTimeImmutable
    {
        return $this->dueOn;
    }

    /** Open with a due date before `$today` (display and filter only: the state never changes by itself). */
    public function isOverdue(DateTimeImmutable $today): bool
    {
        return $this->isOpen() && $this->dueOn instanceof DateTimeImmutable && $this->dueOn->format('Y-m-d') < $today->format('Y-m-d');
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /** @return list<TaskQuestionOption> */
    public function getOptions(): array
    {
        return array_values($this->options->toArray());
    }

    public function option(string $optionId): ?TaskQuestionOption
    {
        foreach ($this->options as $option) {
            if ($option->getId() === $optionId) {
                return $option;
            }
        }

        return null;
    }

    /** @return list<TaskQuestionRecipient> */
    public function getRecipients(): array
    {
        return array_values($this->recipients->toArray());
    }

    public function recipient(string $subjectId): ?TaskQuestionRecipient
    {
        foreach ($this->recipients as $recipient) {
            if ($recipient->getSubjectId() === $subjectId) {
                return $recipient;
            }
        }

        return null;
    }

    /**
     * Every answer, oldest first (the superseded ones included).
     *
     * @return list<TaskQuestionAnswer>
     */
    public function getAnswers(): array
    {
        return array_values($this->answers->toArray());
    }

    /** The answer in force; null while open, and for a question withdrawn before any answer. */
    public function getCurrentAnswer(): ?TaskQuestionAnswer
    {
        foreach ($this->answers as $answer) {
            if ($answer->getId() === $this->currentAnswerId) {
                return $answer;
            }
        }

        return null;
    }

    public function getAnsweredAt(): ?DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function getWithdrawnBy(): ?object
    {
        return $this->withdrawnBy;
    }

    public function getWithdrawnByName(): ?string
    {
        return $this->withdrawnByName;
    }

    public function getWithdrawnAt(): ?DateTimeImmutable
    {
        return $this->withdrawnAt;
    }

    public function getWithdrawReason(): ?string
    {
        return $this->withdrawReason;
    }

    public function getPublishedUrl(): ?string
    {
        return $this->publishedUrl;
    }

    public function getPublishedLabel(): ?string
    {
        return $this->publishedLabel;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /** @internal {@see TaskQuestionManager::ask()} only, before the first flush */
    public function addOption(TaskQuestionOption $option): void
    {
        $this->options->add($option);
    }

    /** @internal {@see TaskQuestionManager::ask()} only, before the first flush */
    public function addRecipient(TaskQuestionRecipient $recipient): void
    {
        $this->recipients->add($recipient);
    }

    /**
     * @internal {@see TaskQuestionManager} only, under the row lock: the new answer
     * supersedes the one in force (both kept)
     */
    public function answer(TaskQuestionAnswer $answer): void
    {
        $answer->number($this->answers->count() + 1);
        $this->getCurrentAnswer()?->supersede($answer);
        $this->answers->add($answer);
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->currentAnswerId = $answer->getId();
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->answeredAt = $answer->getAnsweredAt();
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->state = TaskQuestionState::Answered;
        $this->bump();
    }

    /** @internal {@see TaskQuestionManager::withdraw()} only, under the row lock */
    public function withdraw(?object $by, string $reason, DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->state = TaskQuestionState::Withdrawn;
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->withdrawnBy = $by;
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->withdrawnByName = $by === null ? null : Text::cut(UserLabel::of($by), Task::USER_NAME_MAX);
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->withdrawnAt = $at;
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->withdrawReason = $reason;
        $this->bump();
    }

    /** @internal {@see TaskQuestionManager::publish()} only, under the row lock */
    public function published(string $url, string $label, DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->publishedUrl = $url;
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->publishedLabel = $label;
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->publishedAt = $at;
        $this->bump();
    }

    private function bump(): void
    {
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        ++$this->version;
    }
}
