<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Template;

use Nowo\TaskBoardBundle\ValueObject\TaskBoardSnapshot;

/**
 * Since 2.16.0 (Beacon spec 234, FR-061): where templates are managed — one board (`board` set) or one tenant (`board`
 * null; `tenantKey` null is the scope of the boards without a tenant). Asked of {@see TaskTemplatePermissionInterface::canManage()}.
 */
final readonly class TaskTemplateScope
{
    private function __construct(public ?TaskBoardSnapshot $board, public ?string $tenantKey)
    {
    }

    public static function board(TaskBoardSnapshot $board): self
    {
        return new self($board, $board->tenantKey);
    }

    public static function tenant(?string $tenantKey): self
    {
        return new self(null, $tenantKey);
    }

    public function isBoard(): bool
    {
        return $this->board instanceof TaskBoardSnapshot;
    }
}
