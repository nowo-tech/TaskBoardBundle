<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Since 2.18.0 (Beacon spec 234, FR-028): the type of a native custom field. `apiType()` is ClickUp's name for it.
 */
enum CustomFieldType: string
{
    case Text     = 'text';
    case Number   = 'number';
    case Dropdown = 'dropdown';

    public function apiType(): string
    {
        return match ($this) {
            self::Text     => 'short_text',
            self::Number   => 'number',
            self::Dropdown => 'drop_down',
        };
    }

    public static function fromApi(string $type): ?self
    {
        return match ($type) {
            'short_text', 'text'    => self::Text,
            'number'                => self::Number,
            'drop_down', 'dropdown' => self::Dropdown,
            default                 => null,
        };
    }
}
