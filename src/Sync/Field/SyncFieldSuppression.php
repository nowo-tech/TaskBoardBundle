<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Field;

use Nowo\TaskBoardBundle\Sync\Entity\TaskSyncFieldValue;

use function hash_hmac;
use function is_array;
use function json_encode;
use function ksort;
use function mb_substr;

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

    private string $key;

    public function __construct(string $secret)
    {
        $this->key = hash_hmac('sha256', self::KEY_CONTEXT, $secret, true);
    }

    public function record(mixed $value, string $text): string
    {
        return hash_hmac('sha256', json_encode([$this->canonical($value), mb_substr($text, 0, TaskSyncFieldValue::TEXT_MAX)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->key);
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
