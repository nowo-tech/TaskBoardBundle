<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Dto;

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
 */
final readonly class EraseCursor
{
    public const PHASE_TASKS = 'tasks';

    public const PHASE_COLUMNS = 'columns';

    public const PHASE_BOARD = 'board';

    /**
     * @param array<string, list<string>> $attachmentRefs task id => storage refs
     */
    public function __construct(
        public string $phase = self::PHASE_TASKS,
        public int $tasksDeleted = 0,
        public int $columnsDeleted = 0,
        public array $attachmentRefs = [],
    ) {
    }

    /**
     * @return array{phase: string, tasksDeleted: int, columnsDeleted: int, attachmentRefs: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return ['phase' => $this->phase, 'tasksDeleted' => $this->tasksDeleted, 'columnsDeleted' => $this->columnsDeleted, 'attachmentRefs' => $this->attachmentRefs];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $phase = $data['phase'] ?? null;
        $refs  = [];
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
        );
    }
}
