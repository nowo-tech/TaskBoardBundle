<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\ClientApi\Model;

use InvalidArgumentException;

use function mb_substr;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Since 3.4.0 (client API v1): a tenant a person may use the client API in (`GET me`, the context switcher), as the host's
 * `TaskClientContextPolicyInterface` lists it: a key the client sends back in the context header and a plain-text label.
 */
final readonly class TaskClientContext
{
    /** Longest label kept (characters). */
    public const LABEL_MAX_LENGTH = 120;

    /** The label without control or bidirectional characters, at most {@see self::LABEL_MAX_LENGTH} characters. */
    public string $label;

    /**
     * @param string $key the context key ({@see TaskClientPrincipal::KEY_PATTERN}), e.g. `personal` or `org:<uuid>`
     *
     * @throws InvalidArgumentException when the key does not match {@see TaskClientPrincipal::KEY_PATTERN}
     */
    public function __construct(
        public string $key,
        string $label,
    ) {
        if (preg_match(TaskClientPrincipal::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A task board client context key must match ' . TaskClientPrincipal::KEY_PATTERN . '.');
        }
        $this->label = self::clean($label, self::LABEL_MAX_LENGTH);
    }

    /** Plain text without control or bidirectional characters, cut at `$max` characters. */
    public static function clean(string $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', $text)), 0, $max);
    }

    /**
     * @return array{key: string, label: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
