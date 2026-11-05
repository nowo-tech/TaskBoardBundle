<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Security;

use Nowo\TaskBoardBundle\Dto\EraseCursor;
use Nowo\TaskBoardBundle\Exception\InvalidEraseCursorException;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;
use SensitiveParameter;

use function hash_equals;
use function hash_hmac;
use function ksort;
use function serialize;

use const SORT_STRING;

/**
 * Since 1.25 (audit FP-L3): signs the {@see EraseCursor} that {@see TaskBoardEraser} returns and verifies it on resume.
 *
 * HMAC-SHA256, keyed by a subkey derived from the application secret ({@see self::KEY_CONTEXT}), over a
 * domain-separated message ({@see self::LABEL}) that binds the board id, the actor id and the operation (empty or
 * delete) and covers the run id, the issue time, the anchor task id, the phase, both counts and the attachment refs. A
 * cursor that went through a browser cannot be replayed on another board, by another user or for the other operation,
 * and its refs, phase and counts cannot be changed. Not a service: the eraser builds it from its `$cursorSecret`.
 */
final readonly class EraseCursorSigner
{
    public const LABEL = 'nowo_task_board.erase_cursor.v1';

    /** The context of the subkey derived from the application secret (audit FP-I10). */
    public const KEY_CONTEXT = 'nowo_task_board.erase_cursor';

    private string $key;

    /**
     * The HMAC key is a subkey derived from `$secret` (audit FP-I10), never the raw application secret.
     */
    public function __construct(
        #[SensitiveParameter]
        string $secret,
    ) {
        $this->key = hash_hmac('sha256', self::KEY_CONTEXT, $secret, true);
    }

    /**
     * Returns the cursor carrying its signature for this board, actor and operation.
     */
    public function sign(EraseCursor $cursor, string $boardId, ?string $actorId, bool $deleteBoard): EraseCursor
    {
        return new EraseCursor($cursor->phase, $cursor->tasksDeleted, $cursor->columnsDeleted, $cursor->attachmentRefs, $this->mac($cursor, $boardId, $actorId, $deleteBoard), $cursor->runId, $cursor->issuedAt, $cursor->anchorTaskId);
    }

    /**
     * @throws InvalidEraseCursorException when the cursor is unsigned or signed for another board, actor, operation or state
     */
    public function verify(EraseCursor $cursor, string $boardId, ?string $actorId, bool $deleteBoard): void
    {
        if ($cursor->signature === null || $cursor->signature === '') {
            throw InvalidEraseCursorException::unsigned();
        }
        if (!hash_equals($this->mac($cursor, $boardId, $actorId, $deleteBoard), $cursor->signature)) {
            throw InvalidEraseCursorException::badSignature();
        }
    }

    private function mac(EraseCursor $cursor, string $boardId, ?string $actorId, bool $deleteBoard): string
    {
        $refs = $cursor->attachmentRefs;
        ksort($refs, SORT_STRING);

        return hash_hmac('sha256', serialize([
            self::LABEL,
            $deleteBoard ? 'delete' : 'empty',
            $boardId,
            $actorId,
            $cursor->runId,
            $cursor->issuedAt,
            $cursor->anchorTaskId,
            $cursor->phase,
            $cursor->tasksDeleted,
            $cursor->columnsDeleted,
            $refs,
        ]), $this->key);
    }
}
