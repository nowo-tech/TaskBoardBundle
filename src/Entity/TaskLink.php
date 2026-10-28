<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolverInterface;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'task_board_task_links')]
#[ORM\Index(name: 'idx_task_board_task_links_object', columns: ['object_type', 'object_id'])]
class TaskLink
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class, inversedBy: 'links')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Task $task,
        #[ORM\Column(name: 'link_type', type: 'string', length: 32, enumType: TaskLinkType::class)]
        private TaskLinkType $linkType,
        #[ORM\Column(type: 'string', length: 2048)]
        private string $url,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $label = null,
        #[ORM\Column(name: 'external_id', type: 'string', length: 128, nullable: true)]
        private ?string $externalId = null,
        /** Host object type of a `TaskLinkType::HostObject` link (e.g. "issue"); since 1.10. */
        #[ORM\Column(name: 'object_type', type: 'string', length: 64, nullable: true)]
        private ?string $objectType = null,
        /** Host object id of a `TaskLinkType::HostObject` link; since 1.10. */
        #[ORM\Column(name: 'object_id', type: 'string', length: 191, nullable: true)]
        private ?string $objectId = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function setTask(Task $task): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->task = $task;

        return $this;
    }

    public function getLinkType(): TaskLinkType
    {
        return $this->linkType;
    }

    public function setLinkType(TaskLinkType $linkType): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->linkType = $linkType;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->url = $url;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->label = $label;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->externalId = $externalId;

        return $this;
    }

    /**
     * A link to a host object (`objectType` / `objectId`), resolved by {@see TaskObjectLinkResolverInterface}.
     */
    public static function forHostObject(Task $task, string $objectType, string $objectId, ?string $label = null): self
    {
        return new self(
            task: $task,
            linkType: TaskLinkType::HostObject,
            url: '',
            label: $label,
            objectType: $objectType,
            objectId: $objectId,
        );
    }

    public function getObjectType(): ?string
    {
        return $this->objectType;
    }

    public function getObjectId(): ?string
    {
        return $this->objectId;
    }

    /**
     * Whether the link names a host object (`TaskLinkType::HostObject` with a type and an id).
     */
    public function isHostObject(): bool
    {
        return $this->linkType === TaskLinkType::HostObject && $this->objectType !== null && $this->objectId !== null;
    }

    /**
     * Whether the link names the host object `$objectType` / `$objectId`.
     */
    public function isHostObjectOf(string $objectType, string $objectId): bool
    {
        return $this->isHostObject() && $this->objectType === $objectType && $this->objectId === $objectId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
