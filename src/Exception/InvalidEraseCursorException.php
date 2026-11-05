<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Exception;

use InvalidArgumentException;
use Nowo\TaskBoardBundle\Dto\EraseCursor;
use Nowo\TaskBoardBundle\Service\TaskBoardEraser;

/**
 * Since 1.25: an {@see EraseCursor} handed back to {@see TaskBoardEraser} was refused before anything was touched: it
 * is unsigned, its signature does not match (tampered phase, counts or refs, another board, another actor or the other
 * operation), it has expired, its run is over (audit FP-I9) or its phase is not allowed for the board's current state
 * (audit FP-L3).
 */
final class InvalidEraseCursorException extends InvalidArgumentException
{
    public static function unsigned(): self
    {
        return new self('The erase cursor is not signed. Pass back the cursor the eraser returned.');
    }

    public static function badSignature(): self
    {
        return new self('The erase cursor signature does not match this board, actor, operation or cursor state.');
    }

    public static function incomplete(): self
    {
        return new self('The erase cursor has no run id or issue time. Pass back the cursor the eraser returned.');
    }

    public static function expired(): self
    {
        return new self('The erase cursor has expired. Start a new run with a null cursor.');
    }

    public static function finished(): self
    {
        return new self('The erase run of this cursor is over or the board changed (its anchor task is gone). Start a new run with a null cursor.');
    }

    public static function phaseNotAllowed(string $phase): self
    {
        return new self('The erase cursor phase "' . $phase . '" is not allowed while the board still has tasks or attachment refs.');
    }
}
