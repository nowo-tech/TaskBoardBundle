<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use InvalidArgumentException;

use function preg_match;

/**
 * Since 2.16.0 (spec 234): through which channel, and as which operation, a task enters a column. The channel says who
 * asked: a person in the board UI ({@see self::WEB}), the Task API ({@see self::API}), an in-process caller such as the
 * host's MCP server ({@see self::MCP}), an import ({@see self::IMPORT}) or a host's own label. The bundle passes the Task
 * API principal's channel as it is, so an MCP call never reads as `web`.
 */
final readonly class ColumnTransitionVia
{
    public const WEB = 'web';

    public const API = 'api';

    public const MCP = 'mcp';

    public const IMPORT = 'import';

    /** A task created in the column. */
    public const OP_CREATE = 'create';

    /** A task moved by itself (board drag, previous / next / done, the status of an update). */
    public const OP_MOVE = 'move';

    /** One item of an atomic bulk update. */
    public const OP_BULK = 'bulk';

    /** A checklist item (a tagged subtask, spec 213) ticked or unticked. */
    public const OP_CHECKLIST = 'checklist';

    /**
     * Since 2.16.0 (audit PC-M1): a task moved into the first remaining column because its own column is being deleted
     * (`BoardColumnManager::delete()`); a refusal keeps the column and every task where they are.
     */
    public const OP_COLUMN_DELETE = 'column_delete';

    /**
     * Since 2.16.0 (audit PC-L2): the tags of a task change while it stays in its column (`from` and `to` are the same
     * column), so a rule keyed on tags (a WIP limit per `repo:` tag) is asked about the tags the task will have. A rule
     * that only cares about entering a column allows it.
     */
    public const OP_RETAG = 'retag';

    /**
     * Since 2.18.0 (Beacon spec 234, FR-045): a task moved to another board of its tenant by
     * `Folder\TaskBoardRestructureInterface::moveTasks()` (each task of each moved subtree, into the column it will have
     * there); a refusal moves nothing.
     */
    public const OP_BOARD_MOVE = 'board_move';

    /**
     * @param string $channel `[a-z][a-z0-9_]{0,31}`
     * @param string $operation `[a-z][a-z0-9_]{0,31}`
     */
    public function __construct(
        public string $channel,
        public string $operation = self::OP_MOVE,
    ) {
        foreach ([$channel, $operation] as $label) {
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $label) !== 1) {
                throw new InvalidArgumentException('A column transition channel and operation are lowercase labels of at most 32 characters ([a-z][a-z0-9_]*).');
            }
        }
    }

    public static function web(string $operation = self::OP_MOVE): self
    {
        return new self(self::WEB, $operation);
    }
}
