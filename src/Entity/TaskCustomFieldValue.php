<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Field\TaskCustomFieldManager;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 2.17.0 (Beacon spec 234, FR-028): a task's value of a native custom field — the text, the number (exact decimal,
 * kept as a string) or the dropdown option's id. A task has at most one value per field; no row is "not set".
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_custom_field_values')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_custom_field_values', columns: ['task_id', 'field_id'])]
#[ORM\Index(name: 'idx_task_board_custom_field_values_field', columns: ['field_id'])]
class TaskCustomFieldValue
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** Text, or the dropdown option's id. */
    #[ORM\Column(name: 'text_value', type: 'text', nullable: true)]
    private ?string $textValue = null;

    #[ORM\Column(name: 'number_value', type: 'decimal', precision: 30, scale: 10, nullable: true)]
    private ?string $numberValue = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\ManyToOne(targetEntity: TaskCustomField::class)]
        #[ORM\JoinColumn(name: 'field_id', nullable: false, onDelete: 'CASCADE')]
        private TaskCustomField $field,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getField(): TaskCustomField
    {
        return $this->field;
    }

    public function getTextValue(): ?string
    {
        return $this->textValue;
    }

    public function getNumberValue(): ?string
    {
        return $this->numberValue;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @internal through {@see TaskCustomFieldManager::setField()}, which validates it
     */
    public function change(?string $text, ?string $number): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->textValue = $text;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->numberValue = $number;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
