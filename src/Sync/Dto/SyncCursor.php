<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Dto;

use DateTimeImmutable;
use DateTimeInterface;

use function array_slice;
use function is_array;

/**
 * Since 1.23: where a run stopped — stored on the connection and the run row, never user input, no secret.
 */
final readonly class SyncCursor
{
    /** The most pending dependencies a cursor carries. */
    public const MAX_PENDING = 1000;

    /**
     * @param list<array{0: string, 1: string, 2: string}> $pendingDependencies [task id, other external id, type] of
     *                                                                          relations whose other task was not synced yet
     */
    public function __construct(
        public string $runId,
        public SyncPhase $phase = SyncPhase::Tasks,
        public ?string $providerCursor = null,
        public ?DateTimeImmutable $updatedSince = null,
        public ?DateTimeImmutable $highWatermark = null,
        public int $pagesDone = 0,
        public SyncMode $mode = SyncMode::Initial,
        public array $pendingDependencies = [],
        /** Since audit EG-L2: tasks seen by the run so far (across its slices). */
        public int $tasksSeen = 0,
        /** Since audit EG-L2: the most task pages this run reads (0: the configured default). */
        public int $pageCap = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runId'               => $this->runId,
            'phase'               => $this->phase->value,
            'providerCursor'      => $this->providerCursor,
            'updatedSince'        => $this->updatedSince?->format(DateTimeInterface::ATOM),
            'highWatermark'       => $this->highWatermark?->format(DateTimeInterface::ATOM),
            'pagesDone'           => $this->pagesDone,
            'mode'                => $this->mode->value,
            'pendingDependencies' => array_slice($this->pendingDependencies, 0, self::MAX_PENDING),
            'tasksSeen'           => $this->tasksSeen,
            'pageCap'             => $this->pageCap,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $pending = [];
        foreach (is_array($data['pendingDependencies'] ?? null) ? $data['pendingDependencies'] : [] as $item) {
            if (is_array($item) && isset($item[0], $item[1], $item[2])) {
                $pending[] = [SyncValues::string($item[0]), SyncValues::string($item[1]), SyncValues::string($item[2])];
            }
        }
        $cursor = $data['providerCursor'] ?? null;

        return new self(
            runId: SyncValues::string($data['runId'] ?? ''),
            phase: SyncPhase::tryFrom(SyncValues::string($data['phase'] ?? '')) ?? SyncPhase::Tasks,
            providerCursor: $cursor === null ? null : SyncValues::string($cursor),
            updatedSince: SyncValues::date($data['updatedSince'] ?? null),
            highWatermark: SyncValues::date($data['highWatermark'] ?? null),
            pagesDone: SyncValues::int($data['pagesDone'] ?? 0),
            mode: SyncMode::tryFrom(SyncValues::string($data['mode'] ?? '')) ?? SyncMode::Initial,
            pendingDependencies: array_slice($pending, 0, self::MAX_PENDING),
            tasksSeen: SyncValues::int($data['tasksSeen'] ?? 0),
            pageCap: SyncValues::int($data['pageCap'] ?? 0),
        );
    }
}
