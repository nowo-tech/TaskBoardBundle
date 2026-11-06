<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteCustomField;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function hash_hmac;
use function is_array;
use function mb_substr;

/**
 * Since 1.27.0 (TB-SYNC-3): a task's value of a provider custom field (read-only here: the provider is its source).
 * `value` is typed as {@see RemoteCustomField::$value} says; `text` is the one-line
 * form used by the list search.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_field_values')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_field_values_task_field', columns: ['task_id', 'field_id'])]
#[ORM\Index(name: 'idx_task_board_sync_field_values_field', columns: ['field_id'])]
class TaskSyncFieldValue
{
    public const TEXT_MAX = 500;

    private const ERASED = 'erased';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'value_text', type: 'string', length: self::TEXT_MAX)]
    private string $text;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskSyncField::class)]
        #[ORM\JoinColumn(name: 'field_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncField $field,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\Column(name: 'field_value', type: 'json')]
        private mixed $value,
        string $text,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private DateTimeImmutable $updatedAt,
    ) {
        $this->id   = Uuid::generate()->toString();
        $this->text = mb_substr($text, 0, self::TEXT_MAX);
    }

    /** True when the value changed. */
    public function set(mixed $value, string $text, DateTimeImmutable $now): bool
    {
        $text = mb_substr($text, 0, self::TEXT_MAX);
        if ($this->value === $value && $this->text === $text) {
            return false;
        }
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        [$this->value, $this->text, $this->updatedAt] = [$value, $text, $now];

        return true;
    }

    /**
     * Since 1.27.0 (audit GW-M3): the value is gone. A keyed hash stays, so that a re-sync of the same value does not
     * bring it back; a different value is imported as usual.
     */
    public function erase(DateTimeImmutable $now): void
    {
        // @igor-ignore - Doctrine entity fields; instance-scoped, not a shared service.
        [$this->value, $this->text, $this->updatedAt] = [[self::ERASED => $this->erasedHash($this->text)], '', $now];
    }

    public function isErased(): bool
    {
        return is_array($this->value) && isset($this->value[self::ERASED]);
    }

    /** Whether `$text` is what was erased here (the provider still has it). */
    public function isErasedFor(string $text): bool
    {
        return $this->isErased() && is_array($this->value) && $this->value[self::ERASED] === $this->erasedHash(mb_substr($text, 0, self::TEXT_MAX));
    }

    private function erasedHash(string $text): string
    {
        return hash_hmac('sha256', $text, $this->id);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getField(): TaskSyncField
    {
        return $this->field;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
