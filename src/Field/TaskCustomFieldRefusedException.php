<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use RuntimeException;

/**
 * Since 2.18.0 (FR-028): a custom field write refused before anything was written. `reason`: `not_found` (unknown, of
 * another tenant, or not a field of the task's board), `not_allowed` (the policy), `invalid` (`$params['field']` names
 * what: `name`, `type`, `options`, `value`).
 */
final class TaskCustomFieldRefusedException extends RuntimeException
{
    public const NOT_FOUND   = 'not_found';
    public const NOT_ALLOWED = 'not_allowed';
    public const INVALID     = 'invalid';

    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $params = [],
    ) {
        parent::__construct($reason);
    }
}
