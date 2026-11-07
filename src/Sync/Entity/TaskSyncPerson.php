<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function sprintf;
use function strlen;
use function substr;

/**
 * Since 1.25: one remote person of a directory (unique provider user id per directory) and its mapping to a local
 * user. Names and e-mails are personal data: never logged, cleared by {@see self::anonymise()}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_people')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_people_dir_remote', columns: ['directory_id', 'provider_user_id'])]
#[ORM\Index(name: 'idx_task_board_sync_people_dir_mapping', columns: ['directory_id', 'mapping'])]
#[ORM\Index(name: 'idx_task_board_sync_people_dir_email', columns: ['directory_id', 'email_normalized'])]
#[ORM\Index(name: 'idx_task_board_sync_people_mapped', columns: ['mapped_user_id'])]
class TaskSyncPerson
{
    public const KIND_MEMBER     = 1;
    public const KIND_CREATOR    = 2;
    public const KIND_ASSIGNEE   = 4;
    public const KIND_WATCHER    = 8;
    public const KIND_CHECKLIST  = 16;
    public const KIND_COMMENT    = 32;
    public const KIND_TIME_ENTRY = 64;

    /** Stored name of an anonymised person: "former:<n>", rendered "Former <provider> user #<n>". */
    public const ANONYMOUS_PREFIX = 'former:';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 254, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'email_normalized', type: 'string', length: 254, nullable: true)]
    private ?string $emailNormalized = null;

    #[ORM\Column(name: 'remote_role', type: 'string', length: 16, nullable: true)]
    private ?string $remoteRole = null;

    #[ORM\Column(type: 'string', length: 16, enumType: TaskSyncPersonMapping::class)]
    private TaskSyncPersonMapping $mapping = TaskSyncPersonMapping::Unmatched;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'mapped_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $mappedUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'suggested_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $suggestedUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'mapped_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $mappedBy = null;

    #[ORM\Column(name: 'mapped_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $mappedAt = null;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    private DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'email_changed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $emailChangedAt = null;

    #[ORM\Column(name: 'anonymised_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $anonymisedAt = null;

    #[ORM\Column(name: 'seen_kinds', type: 'smallint', options: ['default' => 0])]
    private int $seenKinds = 0;

    /** Pending re-association (§2.4): its id, so a worker restart resumes and another target is refused. */
    #[ORM\Column(name: 'reassign_id', type: 'string', length: 36, nullable: true)]
    private ?string $reassignId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reassign_to_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $reassignToUser = null;

    #[ORM\Column(name: 'reassign_external', type: 'boolean', options: ['default' => false])]
    private bool $reassignExternal = false;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskSyncDirectory::class)]
        #[ORM\JoinColumn(name: 'directory_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncDirectory $directory,
        #[ORM\Column(name: 'provider_user_id', type: 'string', length: 64)]
        private string $providerUserId,
        #[ORM\Column(type: 'string', length: 120)]
        private string $name,
        #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
        private DateTimeImmutable $firstSeenAt,
    ) {
        $this->id         = Uuid::generate()->toString();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDirectory(): TaskSyncDirectory
    {
        return $this->directory;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    /** The stored name ("former:<n>" once anonymised; see {@see self::anonymousNumber()}). */
    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getEmailNormalized(): ?string
    {
        return $this->emailNormalized;
    }

    public function getRemoteRole(): ?string
    {
        return $this->remoteRole;
    }

    public function getMapping(): TaskSyncPersonMapping
    {
        return $this->mapping;
    }

    /** The mapped user (auto / manual); null otherwise or when the user was deleted. */
    public function getMappedUser(): ?object
    {
        return $this->mapping->isMapped() ? $this->mappedUser : null;
    }

    public function getSuggestedUser(): ?object
    {
        return $this->mapping === TaskSyncPersonMapping::Suggested ? $this->suggestedUser : null;
    }

    public function getMappedBy(): ?object
    {
        return $this->mappedBy;
    }

    public function getMappedAt(): ?DateTimeImmutable
    {
        return $this->mappedAt;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getEmailChangedAt(): ?DateTimeImmutable
    {
        return $this->emailChangedAt;
    }

    public function getAnonymisedAt(): ?DateTimeImmutable
    {
        return $this->anonymisedAt;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymisedAt instanceof DateTimeImmutable;
    }

    /** The n of "Former user #n" (null when not anonymised). */
    public function anonymousNumber(): ?int
    {
        return $this->isAnonymised() ? (int) substr($this->name, strlen(self::ANONYMOUS_PREFIX)) : null;
    }

    public function getSeenKinds(): int
    {
        return $this->seenKinds;
    }

    public function hasSeenKind(int $kind): bool
    {
        return ($this->seenKinds & $kind) === $kind;
    }

    /**
     * Refreshes what the provider says (never on an anonymised person); true when the normalised e-mail changed.
     */
    public function refresh(string $name, ?string $email, ?string $emailNormalized, ?string $role, int $kind, DateTimeImmutable $seenAt): bool
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->seenKinds |= $kind;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastSeenAt = $seenAt;
        if ($this->isAnonymised()) {
            return false;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name !== '' ? $name : $this->name;
        if ($role !== null) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->remoteRole = $role;
        }
        if ($email === null) {
            return false;
        }
        $changed = $this->emailNormalized !== null && $this->emailNormalized !== $emailNormalized;
        if ($changed) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->emailChangedAt = $seenAt;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->email = $email;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->emailNormalized = $emailNormalized;

        return $changed;
    }

    /**
     * Sets the mapping. `$user` is the mapped user (auto / manual) or the suggestion (suggested); null otherwise.
     */
    /** Since 1.29.0: back among those nobody looked at (`mappedBy` is what the summary's count reads). */
    public function clearReview(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mappedBy = null;

        return $this;
    }

    public function map(TaskSyncPersonMapping $mapping, ?object $user, ?object $by, DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mapping = $mapping;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mappedUser = $mapping->isMapped() ? $user : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->suggestedUser = $mapping === TaskSyncPersonMapping::Suggested ? $user : null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mappedBy = $by;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->mappedAt = $at;

        return $this;
    }

    /** The id of the re-association in progress (null = none). */
    public function getReassignId(): ?string
    {
        return $this->reassignId;
    }

    /** The target of the re-association in progress (null = none, or external). */
    public function getReassignToUser(): ?object
    {
        return $this->reassignToUser;
    }

    public function isReassignExternal(): bool
    {
        return $this->reassignExternal;
    }

    /**
     * Records (or clears, with a null id) the re-association in progress.
     *
     * @internal used by the reassigner
     */
    public function setPendingReassign(?string $reassignId, ?object $toUser, bool $external): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reassignId = $reassignId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reassignToUser = $reassignId === null ? null : $toUser;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reassignExternal = $reassignId !== null && $external;

        return $this;
    }

    /** Forget this person: name → "former:<n>", e-mail cleared; references, counts and the mapping stay. */
    public function anonymise(DateTimeImmutable $at): self
    {
        if ($this->isAnonymised()) {
            return $this;
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = sprintf('%s%d', self::ANONYMOUS_PREFIX, $this->directory->nextAnonymousNumber());
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->email = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->emailNormalized = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->anonymisedAt = $at;

        return $this;
    }
}
