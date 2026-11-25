<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanLayout;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanStep;
use Nowo\TaskBoardBundle\Sync\Plan\ReimportMode;
use Nowo\TaskBoardBundle\Sync\Repository\ImportPlanStore;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_values;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Since 3.2.0 (CU-2): a guided import of several boards from one provider workspace, run as one resumable job. It holds
 * the host's opaque connection reference, never a token; its report holds ids and counts only. The wizard's state lives
 * here (not in the session), so a reload, a second tab or another manager sees the same draft; every step checks
 * {@see self::getVersion()}.
 *
 * Users are kept by id (`created_by_id`, `started_by_id`): a plan is read by the worker by id, and erasing a user sets
 * them to null.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_import_plans')]
#[ORM\Index(name: 'idx_task_board_sync_import_plans_tenant', columns: ['tenant_key', 'status'])]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_import_plans_lock', columns: ['lock_key'])]
class TaskSyncImportPlan
{
    /** Options of the plan (no secret); see {@see self::option()}. */
    public const OPTION_CLOSED      = 'closedTasks';
    public const OPTION_ARCHIVED    = 'archivedTasks';
    public const OPTION_SUBTASKS    = 'subtasks';
    public const OPTION_CHECKLISTS  = 'checklists';
    public const OPTION_COMMENTS    = 'comments';
    public const OPTION_ATTACHMENTS = 'attachments';
    public const OPTION_TIME        = 'timeEntries';
    public const OPTION_PEOPLE      = 'peopleFirst';
    public const OPTION_UNMATCHED   = 'deleteUnusedColumns';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'workspace_id', type: 'string', length: 64, nullable: true)]
    private ?string $workspaceId = null;

    #[ORM\Column(name: 'workspace_name', type: 'string', length: 255, nullable: true)]
    private ?string $workspaceName = null;

    /** The people directory of the tenant and workspace (Q-CU-6); every board of the plan shares it. */
    #[ORM\ManyToOne(targetEntity: TaskSyncDirectory::class)]
    #[ORM\JoinColumn(name: 'directory_id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskSyncDirectory $directory = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'draft';

    #[ORM\Column(type: 'string', length: 16)]
    private string $layout = 'per_list';

    #[ORM\Column(name: 'reimport_mode', type: 'string', length: 16)]
    private string $reimportMode = 'update';

    /** The furthest step saved. */
    #[ORM\Column(type: 'string', length: 16)]
    private string $step = 'connect';

    /** @var array<string, bool> */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    /** @var list<string> ids of the provider fields not imported */
    #[ORM\Column(name: 'field_map', type: 'json')]
    private array $fieldMap = [];

    #[ORM\Column(name: 'started_by_id', type: 'string', length: 191, nullable: true)]
    private ?string $startedById = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null; // @phpstan-ignore property.unusedType (written by the store's conditional updates)

    /** Written by {@see ImportPlanStore}'s claim query. */
    #[ORM\Column(name: 'heartbeat_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $heartbeatAt = null; // @phpstan-ignore property.unusedType

    /**
     * Since the audit ZBY-L2 fencing: the token of the slice that claimed the plan; every write of that slice is
     * conditional on it, so a slice that was taken over (or a cancelled plan) cannot write or free the lock any more.
     */
    #[ORM\Column(name: 'run_token', type: 'string', length: 36, nullable: true)]
    private ?string $runToken = null; // @phpstan-ignore property.unusedType

    /** When a paused or rate-limited plan may go on (the inline runner waits for it). */
    #[ORM\Column(name: 'resume_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $resumeAt = null; // @phpstan-ignore property.unusedType (written by the store's conditional updates)

    /** @var array<string, int|list<string>|string> counts and codes only */
    #[ORM\Column(type: 'json')]
    private array $report = [];

    /**
     * One active plan per (tenant, provider, workspace): set while the plan is active, null otherwise. The unique key
     * makes a second start fail (REQ-RUNTIME-003).
     */
    #[ORM\Column(name: 'lock_key', type: 'string', length: 64, nullable: true)]
    private ?string $lockKey = null;

    #[ORM\Column(name: 'error_code', type: 'string', length: 64, nullable: true)]
    private ?string $errorCode = null; // @phpstan-ignore property.unusedType (written by the store's conditional updates)

    #[ORM\Column(name: 'cancel_requested', type: 'boolean')]
    private bool $cancelRequested = false;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(
        #[ORM\Column(name: 'tenant_key', type: 'string', length: 191, nullable: true)]
        private ?string $tenantKey,
        #[ORM\Column(type: 'string', length: 32)]
        private string $provider,
        #[ORM\Column(name: 'connection_ref', type: 'string', length: 191)]
        private string $connectionRef,
        #[ORM\Column(name: 'connection_label', type: 'string', length: 255)]
        private string $connectionLabel,
        #[ORM\Column(name: 'created_by_id', type: 'string', length: 191, nullable: true)]
        private ?string $createdById,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
    ) {
        $this->id              = Uuid::generate()->toString();
        $this->connectionRef   = Text::cut($connectionRef, 191);
        $this->connectionLabel = Text::cut($connectionLabel, 255);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getConnectionRef(): string
    {
        return $this->connectionRef;
    }

    public function getConnectionLabel(): string
    {
        return $this->connectionLabel;
    }

    /** Another connection of the same tenant (the Connect step again): the workspace and what follows are chosen anew. */
    public function useConnection(string $ref, string $label): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->connectionRef = Text::cut($ref, 191);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->connectionLabel = Text::cut($label, 255);
    }

    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getWorkspaceId(): ?string
    {
        return $this->workspaceId;
    }

    public function getWorkspaceName(): ?string
    {
        return $this->workspaceName;
    }

    public function setWorkspace(string $id, string $name): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->workspaceId = Text::cut($id, 64);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->workspaceName = Text::cut($name, 255);
    }

    public function getDirectory(): ?TaskSyncDirectory
    {
        return $this->directory;
    }

    public function setDirectory(?TaskSyncDirectory $directory): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->directory = $directory;
    }

    public function getStatus(): ImportPlanStatus
    {
        return ImportPlanStatus::tryFrom($this->status) ?? ImportPlanStatus::Failed;
    }

    public function getLayout(): ImportPlanLayout
    {
        return ImportPlanLayout::tryFrom($this->layout) ?? ImportPlanLayout::PerList;
    }

    public function setLayout(ImportPlanLayout $layout): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->layout = $layout->value;
    }

    public function getReimportMode(): ReimportMode
    {
        return ReimportMode::tryFrom($this->reimportMode) ?? ReimportMode::UpdateChanged;
    }

    public function setReimportMode(ReimportMode $mode): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->reimportMode = $mode->value;
    }

    public function getStep(): ImportPlanStep
    {
        return ImportPlanStep::tryFrom($this->step) ?? ImportPlanStep::Connect;
    }

    /** Records that `$saved` was saved: the furthest step becomes the one after it (never goes back). */
    public function reach(ImportPlanStep $saved): void
    {
        if ($saved->next()->position() > $this->getStep()->position()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->step = $saved->next()->value;
        }
    }

    /** Goes back to `$step` as the furthest step (an earlier choice changed what follows). */
    public function rewindTo(ImportPlanStep $step): void
    {
        if ($step->position() < $this->getStep()->position()) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->step = $step->value;
        }
    }

    public function option(string $name, bool $default): bool
    {
        $value = $this->options[$name] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<string, bool> $options
     */
    public function setOptions(array $options): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->options = $options;
    }

    /**
     * @return array<string, bool>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return list<string>
     */
    public function getExcludedFields(): array
    {
        return array_values($this->fieldMap);
    }

    /**
     * @param list<string> $fieldIds
     */
    public function setExcludedFields(array $fieldIds): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->fieldMap = array_values($fieldIds);
    }

    public function getStartedById(): ?string
    {
        return $this->startedById;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function markStarted(?string $actorId, DateTimeImmutable $now, string $lockKey): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->startedById = $actorId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->startedAt = $now;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->finishedAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lockKey = $lockKey;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->errorCode = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->cancelRequested = false;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->resumeAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = ImportPlanStatus::Queued->value;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getLockKey(): ?string
    {
        return $this->lockKey;
    }

    /** The last heartbeat of the slice running the plan (null when none runs). */
    public function getHeartbeatAt(): ?DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    /** The token of the slice that holds the plan (null when none runs). */
    public function getRunToken(): ?string
    {
        return $this->runToken;
    }

    public function getResumeAt(): ?DateTimeImmutable
    {
        return $this->resumeAt;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function isCancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    /**
     * @return array<string, int|list<string>|string>
     */
    public function getReport(): array
    {
        return $this->report;
    }

    /**
     * @param array<string, int|list<string>|string> $report counts and codes only
     */
    public function setReport(array $report): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->report = $report;
    }

    public function reportInt(string $key): int
    {
        $value = $this->report[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * @return list<string>
     */
    public function reportList(string $key): array
    {
        $value = $this->report[$key] ?? [];
        $list  = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_string($entry)) {
                $list[] = $entry;
            }
        }

        return $list;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function bumpVersion(): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        ++$this->version;
    }
}
