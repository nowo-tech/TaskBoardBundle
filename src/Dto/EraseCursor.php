<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

use Nowo\TaskBoardBundle\Exception\InvalidEraseCursorException;
use Nowo\TaskBoardBundle\Security\EraseCursorSigner;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Since 1.25: where {@see TaskBoardEraser} stopped. It holds the phase, the running
 * counts for the final event and the storage refs of sync-stored files of tasks not erased yet (the sync attachment
 * rows go with the connection, which is unlinked first). No title, no personal data. Serialisable through
 * {@see self::toArray()} / {@see self::fromArray()} (e.g. a Messenger message).
 *
 * The cursor the eraser returns carries a `signature` ({@see EraseCursorSigner}, audit FP-L3): an HMAC-SHA256 keyed by
 * the application secret, bound to the board, the actor and the operation, over the phase, the counts and the refs.
 * The eraser refuses an unsigned or altered cursor ({@see InvalidEraseCursorException}).
 * Keep it server-side anyway (e.g. a Messenger message): never let a browser hand it back.
 *
 * Since audit FP-I9 the signed state also holds the run id (random, created by the first call), the issue time (unix
 * timestamp of the eraser's clock; the eraser refuses a cursor older than its TTL) and an anchor task id (a task still
 * on the board when the cursor was issued; once the run is over the anchor is gone and the cursor is refused).
 */
final readonly class EraseCursor
{
    public const PHASE_TASKS = 'tasks';

    public const PHASE_COLUMNS = 'columns';

    public const PHASE_BOARD = 'board';

    /**
     * @param array<string, list<string>> $attachmentRefs task id => storage refs
     * @param string|null $signature set by the eraser; a cursor built by hand is unsigned and refused
     * @param string|null $runId the erase run this cursor belongs to
     * @param int|null $issuedAt unix timestamp at which the eraser issued the cursor
     * @param string|null $anchorTaskId a task still on the board when the cursor was issued
     */
    public function __construct(
        public string $phase = self::PHASE_TASKS,
        public int $tasksDeleted = 0,
        public int $columnsDeleted = 0,
        public array $attachmentRefs = [],
        public ?string $signature = null,
        public ?string $runId = null,
        public ?int $issuedAt = null,
        public ?string $anchorTaskId = null,
    ) {
    }

    /**
     * @return array{phase: string, tasksDeleted: int, columnsDeleted: int, attachmentRefs: array<string, list<string>>, signature: string|null, runId: string|null, issuedAt: int|null, anchorTaskId: string|null}
     */
    public function toArray(): array
    {
        return ['phase' => $this->phase, 'tasksDeleted' => $this->tasksDeleted, 'columnsDeleted' => $this->columnsDeleted, 'attachmentRefs' => $this->attachmentRefs, 'signature' => $this->signature, 'runId' => $this->runId, 'issuedAt' => $this->issuedAt, 'anchorTaskId' => $this->anchorTaskId];
    }

    /**
     * Validates the types only: the eraser verifies the signature on resume.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $phase     = $data['phase'] ?? null;
        $signature = $data['signature'] ?? null;
        $refs      = [];
        foreach (is_array($data['attachmentRefs'] ?? null) ? $data['attachmentRefs'] : [] as $taskId => $list) {
            if (!is_string($taskId) || !is_array($list)) {
                continue;
            }
            $refs[$taskId] = array_values(array_filter($list, is_string(...)));
        }

        return new self(
            is_string($phase) && in_array($phase, [self::PHASE_TASKS, self::PHASE_COLUMNS, self::PHASE_BOARD], true) ? $phase : self::PHASE_TASKS,
            is_int($data['tasksDeleted'] ?? null) ? $data['tasksDeleted'] : 0,
            is_int($data['columnsDeleted'] ?? null) ? $data['columnsDeleted'] : 0,
            $refs,
            self::nonEmptyString($signature),
            self::nonEmptyString($data['runId'] ?? null),
            is_int($data['issuedAt'] ?? null) ? $data['issuedAt'] : null,
            self::nonEmptyString($data['anchorTaskId'] ?? null),
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
