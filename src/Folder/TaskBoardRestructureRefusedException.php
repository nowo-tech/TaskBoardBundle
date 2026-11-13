<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Folder;

use RuntimeException;

/**
 * Since 2.17.0: a folder or restructure write was refused before anything was written. `reason`:
 *
 * - `not_found`: a folder, board or task that does not exist, or one of another tenant (never told apart: 404);
 * - `not_allowed`: {@see TaskBoardRestructurePolicyInterface} said no;
 * - `not_empty`: a folder still holding boards cannot be deleted;
 * - `invalid`: a name, position or column map the bundle cannot keep (`$params['field']` names it);
 * - `board_archived`: an archived board is not a target;
 * - `sync_running`: a provider run is in progress on a board the move touches;
 * - `quota`: the host's quota on the open tasks that arrive (`hostMessage()` is the host's reason).
 */
final class TaskBoardRestructureRefusedException extends RuntimeException
{
    public const NOT_FOUND      = 'not_found';
    public const NOT_ALLOWED    = 'not_allowed';
    public const NOT_EMPTY      = 'not_empty';
    public const INVALID        = 'invalid';
    public const BOARD_ARCHIVED = 'board_archived';
    public const SYNC_RUNNING   = 'sync_running';
    public const QUOTA          = 'quota';

    /**
     * @param array<string, int|string> $params
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $params = [],
        ?string $hostMessage = null,
    ) {
        parent::__construct($hostMessage ?? $reason);
    }

    public function hostMessage(): ?string
    {
        return $this->getMessage() !== $this->reason ? $this->getMessage() : null;
    }
}
