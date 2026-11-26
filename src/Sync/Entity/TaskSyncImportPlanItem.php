<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TaskBoardBundle\Support\Text;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanItemStatus;
use Nowo\TaskBoardBundle\Sync\Plan\ImportPlanPhase;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatus;
use Nowo\TaskBoardBundle\Sync\Provider\RemoteStatusType;
use Nowo\TaskBoardBundle\ValueObject\Uuid;

use function array_values;

/**
 * Since 3.2.0 (CU-2): one board of an import plan — the source it follows (a list, a folder or a space), the board it
 * fills (a new one, created when the plan starts, or an existing one) and its status map. Once linked it is an
 * ordinary {@see TaskBoardConnection}: badge, history, People page, completeness report and unlink keep working.
 * Unique per (plan, source), so a plan never creates two boards for one source.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_board_sync_import_plan_items')]
#[ORM\UniqueConstraint(name: 'uniq_task_board_sync_import_plan_items_source', columns: ['plan_id', 'source_type', 'source_id'])]
#[ORM\Index(name: 'idx_task_board_sync_import_plan_items_board', columns: ['board_id'])]
class TaskSyncImportPlanItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'board_id', type: 'string', length: 36, nullable: true)]
    private ?string $boardId = null;

    #[ORM\Column(name: 'create_board', type: 'boolean')]
    private bool $createBoard = true;

    /** @var list<array{status: string, column: string}> */
    #[ORM\Column(name: 'status_map', type: 'json')]
    private array $statusMap = [];

    /** @var list<array{name: string, type: string|null, color: string|null}> the source's statuses, read once */
    #[ORM\Column(name: 'remote_statuses', type: 'json', nullable: true)]
    private ?array $remoteStatuses = null;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status = 'pending';

    #[ORM\Column(type: 'string', length: 16)]
    private string $phase = 'link';

    #[ORM\Column(name: 'connection_id', type: 'string', length: 36, nullable: true)]
    private ?string $connectionId = null;

    #[ORM\Column(name: 'run_id', type: 'string', length: 36, nullable: true)]
    private ?string $runId = null;

    /** @var array<string, int> counts only */
    #[ORM\Column(type: 'json')]
    private array $report = [];

    #[ORM\Column(name: 'error_code', type: 'string', length: 64, nullable: true)]
    private ?string $errorCode = null;

    /**
     * @param list<string> $sourcePath
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskSyncImportPlan::class)]
        #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'CASCADE')]
        private TaskSyncImportPlan $plan,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'source_type', type: 'string', length: 8)]
        private string $sourceType,
        #[ORM\Column(name: 'source_id', type: 'string', length: 128)]
        private string $sourceId,
        #[ORM\Column(name: 'source_label', type: 'string', length: 255)]
        private string $sourceLabel,
        #[ORM\Column(name: 'source_path', type: 'json')]
        private array $sourcePath,
        #[ORM\Column(name: 'board_name', type: 'string', length: 255)]
        private string $boardName,
    ) {
        $this->id          = Uuid::generate()->toString();
        $this->sourceLabel = Text::cut($sourceLabel, 255);
        $this->sourcePath  = array_values($sourcePath);
        $this->boardName   = Text::cut($boardName, 255);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPlan(): TaskSyncImportPlan
    {
        return $this->plan;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /** The container id the connection follows (a list id, `f:<id>` or `s:<id>`). */
    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getSourceLabel(): string
    {
        return $this->sourceLabel;
    }

    /**
     * @return list<string>
     */
    public function getSourcePath(): array
    {
        return $this->sourcePath;
    }

    public function getBoardName(): string
    {
        return $this->boardName;
    }

    public function getBoardId(): ?string
    {
        return $this->boardId;
    }

    public function isCreateBoard(): bool
    {
        return $this->createBoard;
    }

    /** A new board named `$name` (created when the plan starts). */
    public function targetNewBoard(string $name): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->createBoard = true;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardId = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardName = Text::cut($name, 255);
    }

    /** An existing board (chosen in the Boards step), or the board the plan just created for this item. */
    public function targetBoard(string $boardId, string $name): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->createBoard = false;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardId = $boardId;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->boardName = Text::cut($name, 255);
    }

    /**
     * @return list<array{status: string, column: string}>
     */
    public function getStatusMap(): array
    {
        return $this->statusMap;
    }

    /**
     * @param list<array{status: string, column: string}> $map
     */
    public function setStatusMap(array $map): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->statusMap = array_values($map);
    }

    /**
     * The source's statuses, or null when they were not read yet.
     *
     * @return list<RemoteStatus>|null
     */
    public function getRemoteStatuses(): ?array
    {
        if ($this->remoteStatuses === null) {
            return null;
        }
        $statuses = [];
        foreach ($this->remoteStatuses as $position => $row) {
            $statuses[] = new RemoteStatus($row['name'], $row['type'] === null ? null : RemoteStatusType::tryFrom($row['type']), $row['color'], $position);
        }

        return $statuses;
    }

    /**
     * @param list<RemoteStatus> $statuses
     */
    public function setRemoteStatuses(array $statuses): void
    {
        $rows = [];
        foreach ($statuses as $status) {
            $rows[] = ['name' => Text::cut($status->name, 255), 'type' => $status->type?->value, 'color' => $status->color];
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->remoteStatuses = $rows;
    }

    public function getStatus(): ImportPlanItemStatus
    {
        return ImportPlanItemStatus::tryFrom($this->status) ?? ImportPlanItemStatus::Failed;
    }

    public function getPhase(): ImportPlanPhase
    {
        return ImportPlanPhase::tryFrom($this->phase) ?? ImportPlanPhase::Link;
    }

    public function advance(ImportPlanItemStatus $status, ImportPlanPhase $phase, ?string $errorCode = null): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->status = $status->value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->phase = $phase->value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->errorCode = $errorCode;
    }

    /** Back to its first phase that is still to do (a retry or a re-import of the plan). */
    public function reset(): void
    {
        $this->status    = ImportPlanItemStatus::Pending->value;
        $this->phase     = ($this->connectionId === null ? ImportPlanPhase::Link : ImportPlanPhase::Sync)->value;
        $this->errorCode = null;
        $this->runId     = null;
    }

    public function getConnectionId(): ?string
    {
        return $this->connectionId;
    }

    public function setConnectionId(?string $connectionId): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->connectionId = $connectionId;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function setRunId(?string $runId): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->runId = $runId;
    }

    /**
     * @return array<string, int>
     */
    public function getReport(): array
    {
        return $this->report;
    }

    /**
     * @param array<string, int> $report counts only
     */
    public function setReport(array $report): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->report = $report;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
