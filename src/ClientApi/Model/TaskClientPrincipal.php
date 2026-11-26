<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Model;

use InvalidArgumentException;

use function in_array;
use function is_string;
use function preg_match;

/**
 * Since 3.4.0 (client API v1, Beacon Desktop BD-3b): who calls `/task-board/v1`, as the host's
 * `TaskClientPrincipalResolverInterface` answers for one Bearer token — the same shape as ChatKit's `ChatClientPrincipal`
 * (Q-BD-22): the person (the host's security user), the scopes the token holds, a stable non-secret id of the token, the
 * host's label of the device ("Beacon Desktop · Work laptop", for the provenance and the audit) and, for a token bound to
 * one tenant at approval, its context key. Built per request and never stored (FrankenPHP worker mode, kernel reset
 * false).
 */
final readonly class TaskClientPrincipal
{
    /** Every `GET` of the client API (`task-board:read`). */
    public const SCOPE_READ = 'task-board:read';

    /** Every write of the client API (`task-board:write`). `tasks:read` belongs to TimeTrack's `time` family. */
    public const SCOPE_WRITE = 'task-board:write';

    /** A token id or a context key: 1–128 visible ASCII characters (letters, digits, `.`, `_`, `:`, `-`), starting with a letter or digit. */
    public const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';

    /** Longest device label kept (characters). */
    public const TOKEN_NAME_MAX = 120;

    /** @var list<string> */
    public array $scopes;

    /**
     * @param object $user the person the token belongs to (the host's security user)
     * @param array<array-key, mixed> $scopes scopes the token holds (non-string values are dropped)
     * @param string $tokenId a stable, non-secret id of the token ({@see self::KEY_PATTERN})
     * @param string $tokenName the host's label of the device (plain text; cut at {@see self::TOKEN_NAME_MAX})
     * @param string|null $contextKey the tenant the token is bound to; null when the client names it per request
     *
     * @throws InvalidArgumentException when the token id or the context key does not match {@see self::KEY_PATTERN}
     */
    public function __construct(
        public object $user,
        array $scopes,
        public string $tokenId,
        public string $tokenName = '',
        public ?string $contextKey = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $tokenId) !== 1) {
            throw new InvalidArgumentException('A task board client token id must match ' . self::KEY_PATTERN . '.');
        }
        if ($contextKey !== null && preg_match(self::KEY_PATTERN, $contextKey) !== 1) {
            throw new InvalidArgumentException('A task board client context key must match ' . self::KEY_PATTERN . '.');
        }
        $kept = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && !in_array($scope, $kept, true)) {
                $kept[] = $scope;
            }
        }
        $this->scopes = $kept;
    }

    /** Whether the token holds the scope (exact, case-sensitive). */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
