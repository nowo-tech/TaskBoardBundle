<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi;

use LogicException;

use function array_is_list;
use function base64_decode;
use function base64_encode;
use function count;
use function hash_equals;
use function hash_hmac;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function pack;
use function preg_match;
use function random_bytes;
use function rtrim;
use function str_pad;
use function strlen;
use function strtr;
use function substr;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Since 3.4.0 (client API v1): the opaque cursor of a list — the position after the last row a page scanned (its sort
 * values and id), **sealed** (audit ZCS-L2): the values are encrypted (HMAC-SHA-256 in counter mode with a random
 * 96-bit nonce) and authenticated (HMAC-SHA-256, 128-bit tag, encrypt-then-MAC) with two keys derived from
 * `kernel.secret`, and the list it belongs to (`$kind`: the list, its sort, its board or context) is part of the tag. A
 * cursor never shows the id, date or position of a row the reader may not see, and a cursor that was changed, made for
 * another list or another secret is `invalid_cursor`. Values are strings and integers only. Stateless (worker mode):
 * the keys are derived once, nothing of a request is kept.
 *
 * @internal
 */
final readonly class TaskClientCursor
{
    /** Longest cursor accepted (bytes). */
    public const MAX_LENGTH = 1024;

    private const NONCE = 12;

    private const TAG = 16;

    /** Since 3.4.1 (audit ZCS-v-I2): the plaintext is padded to a multiple of this many bytes. */
    private const PAD = 32;

    private string $encryptionKey;

    private string $macKey;

    /**
     * @throws LogicException when the secret is empty (audit ZCS-v-I4): the keys would be public, so anyone could read
     *                        and forge cursors
     */
    public function __construct(string $secret)
    {
        if (trim($secret) === '') {
            throw new LogicException('The TaskBoard client API needs a non-empty kernel.secret (framework.secret, usually APP_SECRET): its cursors are sealed with keys derived from it.');
        }
        $this->encryptionKey = hash_hmac('sha256', 'nowo_task_board.client_api.cursor.encrypt', $secret, true);
        $this->macKey        = hash_hmac('sha256', 'nowo_task_board.client_api.cursor.mac', $secret, true);
    }

    /**
     * @param list<int|string> $values
     */
    public function encode(string $kind, array $values): string
    {
        $plain = (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Audit ZCS-v-I2: padded with spaces (JSON whitespace) to a multiple of PAD bytes, so the length does not tell
        // how many digits a value has. Unpadded cursors of 3.4.0 still decode.
        $plain  = str_pad($plain, intdiv(strlen($plain) + self::PAD - 1, self::PAD) * self::PAD);
        $nonce  = random_bytes(self::NONCE);
        $cipher = $plain ^ $this->stream($nonce, strlen($plain));

        return rtrim(strtr(base64_encode($nonce . $this->tag($kind, $nonce, $cipher) . $cipher), '+/', '-_'), '=');
    }

    /**
     * The values of a cursor of this kind with `$count` values, else null (not a cursor, changed, another kind, another
     * shape, another secret).
     *
     * @return list<int|string>|null
     */
    public function decode(string $cursor, string $kind, int $count): ?array
    {
        if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= self::NONCE + self::TAG) {
            return null;
        }
        $nonce  = substr($raw, 0, self::NONCE);
        $cipher = substr($raw, self::NONCE + self::TAG);
        if (!hash_equals($this->tag($kind, $nonce, $cipher), substr($raw, self::NONCE, self::TAG))) {
            return null;
        }
        // Authenticated, so this server wrote it: JSON of a list (anything else is null and refused below).
        $values = json_decode($cipher ^ $this->stream($nonce, strlen($cipher)), true, 4);
        if (!is_array($values) || !array_is_list($values) || count($values) !== $count) {
            return null;
        }
        foreach ($values as $value) {
            if (!is_int($value) && (!is_string($value) || strlen($value) > 191)) {
                return null;
            }
        }

        /* @var list<int|string> $values */
        return $values;
    }

    private function tag(string $kind, string $nonce, string $cipher): string
    {
        return substr(hash_hmac('sha256', 'v1|' . $kind . '|' . $nonce . $cipher, $this->macKey, true), 0, self::TAG);
    }

    /** The key stream: HMAC-SHA-256(key, nonce || counter) blocks. */
    private function stream(string $nonce, int $length): string
    {
        $stream = '';
        for ($block = 0; strlen($stream) < $length; ++$block) {
            $stream .= hash_hmac('sha256', $nonce . pack('N', $block), $this->encryptionKey, true);
        }

        return substr($stream, 0, $length);
    }
}
