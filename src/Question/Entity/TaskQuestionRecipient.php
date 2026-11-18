<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Question\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 2.23.0 (Beacon spec 242): who a question is addressed to — an opaque subject id of the host's recipient resolver
 * (e.g. `user:42`, `contact:7`), compared byte for byte (binary collation on MySQL / MariaDB), with the label it had when
 * asked. `external`: the recipient answers through the host's signed link, not the bundle's pages.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_task_board_task_question_recipients_subject', columns: ['question_id', 'subject_id'])]
#[ORM\Index(name: 'idx_task_board_task_question_recipients_subject', columns: ['subject_id'])]
class TaskQuestionRecipient
{
    public const TABLE = 'task_board_task_question_recipients';

    public const SUBJECT_MAX = 191;

    public const LABEL_MAX = 180;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskQuestion::class, inversedBy: 'recipients')]
        #[ORM\JoinColumn(name: 'question_id', nullable: false, onDelete: 'CASCADE')]
        private TaskQuestion $question,
        #[ORM\Column(name: 'subject_id', type: 'string', length: 191)]
        private string $subjectId,
        #[ORM\Column(type: 'string', length: 180)]
        private string $label,
        #[ORM\Column(type: 'boolean')]
        private bool $external = false,
        #[ORM\Column(type: 'smallint')]
        private int $position = 0,
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

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
