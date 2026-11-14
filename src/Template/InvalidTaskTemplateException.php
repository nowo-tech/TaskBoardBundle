<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use InvalidArgumentException;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): a template {@see TaskTemplateManager} refuses to save; nothing was written.
 * `$field` names what is wrong (`name`, `body`, `default_tags`, `default_column_id`, `tenant_key`).
 */
final class InvalidTaskTemplateException extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
