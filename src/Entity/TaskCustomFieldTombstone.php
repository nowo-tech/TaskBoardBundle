<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

/**
 * Since 3.3.0 (CU-3, audit ZCF-L2): an imported custom field that was deleted here — its board and provenance marker
 * (`clickup:<field id>`). While it exists a re-import does not create the field again: the provider's values stay
 * synced, read-only fields. The board's Custom fields page lists it and can clear it (*Import it again*).
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_custom_field_tombstones')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_custom_field_tombstones', columns: ['board_id', 'source_ref'])]
class TaskCustomFieldTombstone
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable')]
    private DateTimeImmutable $deletedAt;

    /** The field's name when it was deleted (shown on the page). */
    #[ORM\Column(type: 'string', length: 128)]
    private string $name;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskBoard::class)]
        #[ORM\JoinColumn(name: 'board_id', nullable: false, onDelete: 'CASCADE')]
        private TaskBoard $board,
        #[ORM\Column(name: 'source_ref', type: 'string', length: 191)]
        private string $sourceRef,
        string $name,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->deletedAt = new DateTimeImmutable();
        $this->name      = Text::cut($name, TaskCustomField::NAME_MAX);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): TaskBoard
    {
        return $this->board;
    }

    public function getSourceRef(): string
    {
        return $this->sourceRef;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDeletedAt(): DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
