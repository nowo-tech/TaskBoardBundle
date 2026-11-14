<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Field;

use Nowo\TaskBoardBundle\Enum\CustomFieldOperator;

/**
 * Since 2.19.0 (FR-028): one custom field filter — the field, an operator and its values (one for `=`, `!=`, `<`, `>`;
 * one or more for `ANY`; none for "is set" / "is not set"). A dropdown is compared by option id or option name.
 */
final readonly class TaskCustomFieldCondition
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public string $fieldId,
        public CustomFieldOperator $operator,
        public array $values = [],
    ) {
    }
}
