<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 2.23.0 (Beacon spec 242): one option of a question (0 or 2–4 per question, at most one recommended). Never
 * changed once the question is asked.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_task_board_task_question_options_position', columns: ['question_id', 'position'])]
class TaskQuestionOption
{
    public const TABLE = 'task_board_task_question_options';

    public const LABEL_MAX = 120;

    public const DESCRIPTION_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskQuestion::class, inversedBy: 'options')]
        #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
        private TaskQuestion $question,
        #[ORM\Column(type: 'smallint')]
        private int $position,
        #[ORM\Column(type: 'string', length: 120)]
        private string $label,
        #[ORM\Column(type: 'string', length: 500, nullable: true)]
        private ?string $description = null,
        #[ORM\Column(type: 'boolean')]
        private bool $recommended = false,
    ) {
        $this->id = Uuid::generate()->toString();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getQuestion(): TaskQuestion
    {
        return $this->question;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isRecommended(): bool
    {
        return $this->recommended;
    }
}
