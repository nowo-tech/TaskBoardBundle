<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_values;

/**
 * Since 2.23.0 (Beacon spec 242): one answer to a question — the options picked (ids of the question's options), the
 * *Other* free text, the recipient it answers for (`answeredBySubject`), who answered (a signed-in user, or null for an
 * external answer through the host's link, with the name the host gave) and where (`via`: web, app, api, mcp, external).
 *
 * Never edited or deleted: a later answer supersedes it ({@see self::getSupersededAt()}), and both are kept.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_task_board_task_question_answers_question', columns: ['question_id', 'answer_number'])]
class TaskQuestionAnswer
{
    public const TABLE = 'task_board_task_question_answers';

    public const OTHER_MAX = 5000;

    public const VIA_EXTERNAL = 'external';

    /** The values of {@see self::getVia()}. */
    public const VIAS = ['web', 'app', 'api', 'mcp', self::VIA_EXTERNAL];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'answered_by_name', type: 'string', length: 180, nullable: true)]
    private ?string $answeredByName;

    #[ORM\Column(name: 'superseded_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $supersededAt = null;

    #[ORM\Column(name: 'superseded_by_id', type: 'string', length: 36, nullable: true)]
    private ?string $supersededById = null;

    /** 1 for the first answer of its question, then +1: the order of the answers (two may share a second). */
    #[ORM\Column(name: 'answer_number', type: 'integer')]
    private int $sequence = 1;

    /**
     * @param list<string> $selectedOptionIds
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskQuestion::class, inversedBy: 'answers')]
        #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
        private TaskQuestion $question,
        #[ORM\Column(name: 'selected_option_ids', type: 'json')]
        private array $selectedOptionIds,
        #[ORM\Column(name: 'other_text', type: 'text', nullable: true)]
        private ?string $otherText,
        #[ORM\Column(name: 'answered_by_subject', type: 'string', length: 191)]
        private string $answeredBySubject,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'answered_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $answeredBy,
        ?string $answeredByName,
        #[ORM\Column(type: 'string', length: 8)]
        private string $via,
        #[ORM\Column(name: 'answered_at', type: 'datetime_immutable')]
        private DateTimeImmutable $answeredAt,
    ) {
        $this->id                = Uuid::generate()->toString();
        $this->selectedOptionIds = array_values($selectedOptionIds);
        $this->answeredByName    = Text::cutNullable($answeredByName, Task::USER_NAME_MAX);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getQuestion(): TaskQuestion
    {
        return $this->question;
    }

    /** @return list<string> */
    public function getSelectedOptionIds(): array
    {
        return $this->selectedOptionIds;
    }

    public function selects(string $optionId): bool
    {
        foreach ($this->selectedOptionIds as $id) {
            if ($id === $optionId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<TaskQuestionOption> the options picked, in the question's order */
    public function getSelectedOptions(): array
    {
        $picked = [];
        foreach ($this->question->getOptions() as $option) {
            if ($this->selects($option->getId())) {
                $picked[] = $option;
            }
        }

        return $picked;
    }

    public function getOtherText(): ?string
    {
        return $this->otherText;
    }

    public function getAnsweredBySubject(): string
    {
        return $this->answeredBySubject;
    }

    public function getAnsweredBy(): ?object
    {
        return $this->answeredBy;
    }

    public function getAnsweredByName(): ?string
    {
        return $this->answeredByName;
    }

    public function getVia(): string
    {
        return $this->via;
    }

    public function isExternal(): bool
    {
        return $this->via === self::VIA_EXTERNAL;
    }

    public function getAnsweredAt(): DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function getSupersededAt(): ?DateTimeImmutable
    {
        return $this->supersededAt;
    }

    public function getSupersededById(): ?string
    {
        return $this->supersededById;
    }

    public function isSuperseded(): bool
    {
        return $this->supersededAt instanceof DateTimeImmutable;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    /** @internal {@see TaskQuestion::answer()} only */
    public function number(int $sequence): void
    {
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->sequence = $sequence;
    }

    /** @internal {@see TaskQuestion::answer()} only */
    public function supersede(self $by): void
    {
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->supersededAt = $by->getAnsweredAt();
        // @igor-ignore - Doctrine entity field: a row of one request, not a shared service.
        $this->supersededById = $by->getId();
    }
}
