<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Service;

/**
 * Since 2.31.0 (REQ-RUNTIME-003 rule 4): the committed revision of one task, read with its row locked until the
 * transaction ends, so the edit form's save (`baseDigest`) can tell whether someone saved the task between this
 * request's load and its lock, before anything is written. Implemented by `Repository\DoctrineOrmOrderedRowLock` beside
 * {@see OrderedRowLockInterface}; a lock that does not implement it leaves the form's check to the loaded task.
 *
 * @internal an SPI of the bundle's own domain services; a host depends on their ports
 */
interface TaskRevisionLockInterface
{
    /**
     * Inside {@see OrderedRowLockInterface::transactional()} only, after the columns the write enters are locked (fixed
     * order: columns, then tasks).
     *
     * @return int|null null when the task no longer exists
     */
    public function lockedTaskRevision(string $taskId): ?int;
}
