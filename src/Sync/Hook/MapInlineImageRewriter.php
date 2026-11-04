<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Sync\Hook;

use Nowo\TaskBoardBundle\Entity\Task;

use function preg_replace_callback;

/**
 * Since 1.23: each stored image points to its copy; any other image becomes the text "[image not copied]" (no
 * hot-linking of provider files).
 */
final readonly class MapInlineImageRewriter implements InlineImageRewriterInterface
{
    public const PLACEHOLDER = '[image not copied]';

    public function rewrite(Task $task, string $markdown, array $storedByRemoteUrl): string
    {
        return (string) preg_replace_callback(
            '/!\[([^\]\n]*)\]\(\s*<?([^)\s>]+)>?(\s+"[^"]*")?\s*\)/',
            static function (array $match) use ($storedByRemoteUrl): string {
                $stored = $storedByRemoteUrl[$match[2]] ?? null;

                return $stored instanceof StoredAttachment ? '![' . $match[1] . '](' . $stored->url . ')' : self::PLACEHOLDER;
            },
            $markdown,
        );
    }
}
