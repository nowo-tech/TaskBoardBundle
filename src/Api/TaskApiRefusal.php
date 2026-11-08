<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Api;

use RuntimeException;

/**
 * Since 2.2.0 (spec 213): a request refused, with what the client sees: `{"err": message, "ECODE": ecode}`. The message
 * goes to the client as it is, so it names no id or person.
 */
final class TaskApiRefusal extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $ecode, string $message)
    {
        parent::__construct($message);
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
