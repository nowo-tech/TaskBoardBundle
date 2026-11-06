<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

/**
 * Since 1.27.0 (TB-SYNC-3): the type of a provider custom field. A provider type the bundle does not know is kept as
 * `text` (its value as text): a field is never dropped.
 */
enum SyncFieldType: string
{
    case Text         = 'text';
    case Number       = 'number';
    case Money        = 'money';
    case Date         = 'date';
    case Dropdown     = 'dropdown';
    case Labels       = 'labels';
    case Checkbox     = 'checkbox';
    case Url          = 'url';
    case Email        = 'email';
    case Phone        = 'phone';
    case Rating       = 'rating';
    case Progress     = 'progress';
    case People       = 'people';
    case Relationship = 'relationship';
}
