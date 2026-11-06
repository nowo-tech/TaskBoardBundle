<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;

use function hash_hmac;
use function is_array;
use function json_encode;
use function ksort;
use function mb_substr;
use function preg_match;
use function str_contains;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 1.27.0 (audit GWd-L2): the suppression record of an erased custom field value, so that a re-sync of the same
 * value does not bring it back. HMAC-SHA256 with a subkey of the application secret, over the stored value and its text:
 * without the secret it cannot be tested against guesses; with the secret it can, so it may still be personal data.
 * Changing the secret makes the erased values importable again.
 */
final readonly class SyncFieldSuppression
{
    private const KEY_CONTEXT = 'nowo_task_board.sync_field_suppression';

    /** Characters from which a line's value is recorded without its field name. */
    private const MIN_VALUE_ALONE = 8;

    private string $key;

    public function __construct(string $secret)
    {
        $this->key = hash_hmac('sha256', self::KEY_CONTEXT, $secret, true);
    }

    public function record(mixed $value, string $text): string
    {
        return hash_hmac('sha256', json_encode([$this->canonical($value), mb_substr($text, 0, TaskSyncFieldValue::TEXT_MAX)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->key);
    }

    /**
     * Since 1.28.0: the record of a line of the "Fields" document. A value that identifies something is recorded alone, so
     * renaming the field does not bring it back (audit HId-L3). A short one without `@`, such as "yes" or "12", could equal
     * another field's value in the same document, so it is recorded with its line, as is a line that is not a field line.
     */
    public function recordLine(string $line): string
    {
        if (preg_match('/^- \*\*.+?\*\*: (.+)$/D', $line, $parts) === 1 && (mb_strlen($parts[1]) >= self::MIN_VALUE_ALONE || str_contains($parts[1], '@'))) {
            return $this->record(null, 'value:' . $parts[1]);
        }

        return $this->record(null, $line);
    }

    /** MySQL's JSON type reorders keys: the order does not change the record. */
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }

        return $value;
    }
}
