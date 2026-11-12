<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\MultiBoard;

use InvalidArgumentException;

use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;

/**
 * Since 2.13.0 (Beacon D-20261112-01): who is looking at the multi-board task list, as plain values (REQ-ARCH-001
 * rule 3: no `UserInterface`, no `Request`). The bundle builds it from the signed-in user's `getId()` and the route
 * parameters of the page (e.g. an organisation slug in `multi_board.path`); a DDD host builds it from its own ids.
 */
final readonly class MultiBoardViewer
{
    /** Longest user id accepted (BUNDLES_FIX_RULES "Identifier columns"). */
    public const MAX_ID_LENGTH = 128;

    /** Longest context key or value accepted. */
    public const MAX_CONTEXT_LENGTH = 255;

    /**
     * @param string $userId the viewer's user id, exactly as `user_class::getId()` returns it (compared with `===`)
     * @param array<string, string> $context route parameters of the page (no `_` keys), for a provider that scopes by them
     */
    public function __construct(
        public string $userId,
        public array $context = [],
    ) {
        if ($userId === '' || mb_strlen($userId) > self::MAX_ID_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $userId) === 1) {
            throw new InvalidArgumentException(sprintf('A multi-board viewer needs a user id of 1 to %d printable characters.', self::MAX_ID_LENGTH));
        }
        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value) || mb_strlen($key) > self::MAX_CONTEXT_LENGTH || mb_strlen($value) > self::MAX_CONTEXT_LENGTH) {
                throw new InvalidArgumentException(sprintf('A multi-board viewer context holds string keys and values of at most %d characters.', self::MAX_CONTEXT_LENGTH));
            }
        }
    }

    /** One route parameter of the page, or null. */
    public function context(string $key): ?string
    {
        return $this->context[$key] ?? null;
    }
}
