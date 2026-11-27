<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use RuntimeException;

/**
 * Since 3.4.0 (client API v1): a refusal as the client sees it — `{"ok": false, "error": <code>}` plus `$extra` fields
 * (`field`, `currentRevision`, `policyCode`…) and headers (`Retry-After`, `WWW-Authenticate`). The code is a fixed
 * snake_case word; no message, id or name of another person is ever in it.
 */
final class TaskClientApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($error);
    }

    /** Out of reach, unknown, foreign and out-of-context ids: one answer. */
    public static function notFound(): self
    {
        return new self('not_found', 404);
    }

    public static function invalidField(string $field): self
    {
        return new self('invalid_field', 400, ['field' => $field]);
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return ['ok' => false, 'error' => $this->error] + $this->extra;
    }
}
