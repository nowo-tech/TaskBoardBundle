<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Enum;

/**
 * Since 2.17.0 (Beacon spec 234, FR-028): the operators of a custom field filter, in the Task API's (ClickUp's) spelling.
 * `<` and `>` are for number fields; `IS NOT NULL` is "is set", `ANY` is "in".
 */
enum CustomFieldOperator: string
{
    case Equals      = '=';
    case NotEquals   = '!=';
    case LessThan    = '<';
    case GreaterThan = '>';
    case IsSet       = 'IS NOT NULL';
    case IsNotSet    = 'IS NULL';
    case In          = 'ANY';

    /** Also `≠`, `<>`, `is_set`, `is_not_set`, `in` (the list view's names). */
    public static function parse(string $operator): ?self
    {
        return match (trim($operator)) {
            '=', 'eq'                      => self::Equals,
            '!=', '≠', '<>', 'ne'          => self::NotEquals,
            '<', 'lt'                      => self::LessThan,
            '>', 'gt'                      => self::GreaterThan,
            'IS NOT NULL', 'is_set', 'set' => self::IsSet,
            'IS NULL', 'is_not_set'        => self::IsNotSet,
            'ANY', 'in'                    => self::In,
            default                        => null,
        };
    }

    public function needsValue(): bool
    {
        return $this !== self::IsSet && $this !== self::IsNotSet;
    }
}
