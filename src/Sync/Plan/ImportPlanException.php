<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Plan;

use RuntimeException;

/**
 * Since 3.2.0 (CU-2): a wizard step or a start was refused. `$reason` is a code the page translates
 * (`task_board.import_plan.error.<reason>`); `$detail` is an extra text already safe to show (a host quota message).
 * Never a provider answer or a credential.
 */
final class ImportPlanException extends RuntimeException
{
    public const STALE             = 'stale';
    public const INVALID           = 'invalid';
    public const WORKSPACE_BUSY    = 'workspace_busy';
    public const CREDENTIAL        = 'credential_unavailable';
    public const PROVIDER          = 'provider_unavailable';
    public const NO_SOURCES        = 'no_sources';
    public const TOO_MANY_ITEMS    = 'too_many_items';
    public const SINGLE_NEEDS_ONE  = 'single_needs_one';
    public const BOARD_UNAVAILABLE = 'board_unavailable';
    public const QUOTA             = 'quota';
    public const NOT_EDITABLE      = 'not_editable';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $detail = null,
    ) {
        parent::__construct('Import plan refused: ' . $reason);
    }
}
