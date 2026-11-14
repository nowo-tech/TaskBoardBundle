<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ValueObject;

use function preg_match;

/**
 * Since 2.16.0 (spec 234): a column transition policy's answer. A refusal carries a code the Task API answers as its
 * `ECODE` with a 403 (`STATUS_HUMANS_ONLY`, `WIP_LIMIT`, `BLOCK_REASON_REQUIRED`, …: `[A-Z][A-Z0-9_]{0,63}`, else
 * `STATUS_REFUSED`) and a message the person or the client reads as it is (the host translates it; plain text, never
 * HTML; empty: the bundle's own text). The board UI shows that message when a drag or a form is refused.
 */
final readonly class ColumnTransitionDecision
{
    public const CODE_REFUSED = 'STATUS_REFUSED';

    public const CODE_HUMANS_ONLY = 'STATUS_HUMANS_ONLY';

    private function __construct(
        public bool $allowed,
        public string $code,
        public string $message,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, '', '');
    }

    public static function refuse(string $code = self::CODE_REFUSED, string $message = ''): self
    {
        return new self(false, preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code) === 1 ? $code : self::CODE_REFUSED, $message);
    }
}
