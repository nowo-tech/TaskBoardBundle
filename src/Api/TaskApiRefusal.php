<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use Nowo\TaskBoardBundle\Exception\ColumnTransitionRefusedException;
use Nowo\TaskBoardBundle\Transition\HumansOnlyColumnTransitionPolicy;
use Nowo\TaskBoardBundle\Tree\TaskReparentRefusedException;
use RuntimeException;

/**
 * Since 2.2.0 (spec 213): a request refused, with what the client sees: `{"err": message, "ECODE": ecode}`. The message
 * goes to the client as it is, so it names no id or person.
 */
final class TaskApiRefusal extends RuntimeException
{
    /** Since 2.16.0 (spec 234, FR-015): a task may not enter a column flagged *humans only* this way. */
    public const STATUS_HUMANS_ONLY = 'STATUS_HUMANS_ONLY';

    /** Since 2.16.0: the host's column transition policy refused a column that is not flagged *humans only*. */
    public const STATUS_REFUSED = 'STATUS_REFUSED';

    /** Since 2.16.0 (spec 234, FR-018): the task changed since the revision the client sent. */
    public const TASK_STALE = 'TASK_409';

    /** Since 2.20.0 (Beacon spec 234, T004): 400, the new parent is on another board. */
    public const PARENT_OTHER_BOARD = 'PARENT_001';

    /** Since 2.20.0: 400, the new parent is the task itself. */
    public const PARENT_SELF = 'PARENT_002';

    /** Since 2.20.0: 409, the new parent is one of the task's subtasks (at any depth): it would close a cycle. */
    public const PARENT_DESCENDANT = 'PARENT_003';

    /** Since 2.20.0: 409, a concurrent change of the task tree held the rows; nothing was written, send it again. */
    public const PARENT_CONFLICT = 'PARENT_004';

    /**
     * @param array<string, mixed> $extra since 2.16.0: more top-level keys of the answer (`current_revision`, `failures`);
     *                                    never `err` or `ECODE`
     */
    public function __construct(public readonly int $status, public readonly string $ecode, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /**
     * Since 2.16.0 (spec 234): 403 with the policy's code (`STATUS_HUMANS_ONLY`, `WIP_LIMIT`, …; `STATUS_REFUSED` by
     * default) and its message (the bundle's own text when it gave none).
     */
    public static function transitionRefused(ColumnTransitionRefusedException $refused): self
    {
        $decision = $refused->decision;

        return new self(403, $decision->code, $decision->message !== '' ? $decision->message : 'This status may not be set this way');
    }

    /**
     * Since 2.16.0 (audit PC-M1): 403 `STATUS_HUMANS_ONLY`, byte for byte the answer of a status the bundled *humans only*
     * policy refuses, for a column change an `api` / `mcp` caller may not make (it would lift the gate).
     */
    public static function humansOnly(): self
    {
        return new self(403, self::STATUS_HUMANS_ONLY, HumansOnlyColumnTransitionPolicy::MESSAGE);
    }

    /** Since 2.16.0 (spec 234, FR-018): 409 `TASK_409` with the task's current revision. */
    public static function stale(int $currentRevision): self
    {
        return new self(409, self::TASK_STALE, 'The task changed: read it again', ['current_revision' => $currentRevision]);
    }

    /**
     * Since 2.20.0 (Beacon spec 234, T004): the answer to a refused parent change. A task or parent that vanished meanwhile
     * is `ACCESS_001`, as any id out of reach.
     */
    public static function reparentRefused(TaskReparentRefusedException $refused): self
    {
        return match ($refused->reason) {
            TaskReparentRefusedException::OTHER_BOARD => new self(400, self::PARENT_OTHER_BOARD, 'The parent is on another board'),
            TaskReparentRefusedException::SELF        => new self(400, self::PARENT_SELF, 'A task cannot be its own parent'),
            TaskReparentRefusedException::DESCENDANT  => new self(409, self::PARENT_DESCENDANT, 'The parent is one of the task\'s subtasks'),
            TaskReparentRefusedException::CONFLICT    => new self(409, self::PARENT_CONFLICT, 'The task tree changed meanwhile: try again'),
            default                                   => self::notFound(),
        };
    }

    /**
     * Since 2.16.0: the JSON body of the answer.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return ['err' => $this->getMessage(), 'ECODE' => $this->ecode] + $this->extra;
    }

    public static function notFound(): self
    {
        return new self(404, 'ACCESS_001', 'Not found');
    }

    public static function levelRefused(): self
    {
        return new self(403, 'TOKEN_001', 'This token may not do this');
    }

    public static function permissionRefused(): self
    {
        return new self(403, 'ACCESS_002', 'You may not do this');
    }

    public static function planRefused(): self
    {
        return new self(403, 'PLAN_001', 'The tasks module is not in this plan');
    }
}
