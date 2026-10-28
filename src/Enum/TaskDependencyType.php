<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Relationship between two tasks.
 */
enum TaskDependencyType: string
{
    case Blocks     = 'blocks';
    case BlockedBy  = 'blocked_by';
    case Related    = 'related';
    case Duplicates = 'duplicates';

    /**
     * Since 1.15: how the link reads from one of its tasks — the source's view (`blocks`, `blocked_by`, `related`,
     * `duplicates`) or, with `$incoming`, the target's (`blocked_by`, `blocks`, `related`, `duplicated_by`). The suffix
     * of the `task_board.dependency.relation.*` labels.
     */
    public function relation(bool $incoming = false): string
    {
        return match ($this) {
            self::Blocks     => $incoming ? 'blocked_by' : 'blocks',
            self::BlockedBy  => $incoming ? 'blocks' : 'blocked_by',
            self::Related    => 'related',
            self::Duplicates => $incoming ? 'duplicated_by' : 'duplicates',
        };
    }
}
