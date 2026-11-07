<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Board;

use InvalidArgumentException;

use function preg_match;

/**
 * Since 1.30.0 (spec 211): one choice of the create form's scope step. The host decides which exist for a user.
 */
final readonly class BoardScope
{
    /**
     * @param string $id `[a-z0-9_-]{1,32}`
     * @param string $label shown as it is (already translated)
     * @param string|null $description shown after the label in the scope step, escaped
     * @param array<string, BoardScopeField> $fields by field name, `[A-Za-z0-9_]+`
     * @param bool $preselected the step opens on this scope (e.g. the page the dialog came from)
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $icon = null,
        public ?string $description = null,
        public array $fields = [],
        public bool $preselected = false,
    ) {
        if (preg_match('/^[a-z0-9_-]{1,32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('A board scope id is [a-z0-9_-]{1,32}.');
        }
    }
}
