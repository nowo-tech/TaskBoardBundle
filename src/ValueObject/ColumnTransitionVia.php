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
