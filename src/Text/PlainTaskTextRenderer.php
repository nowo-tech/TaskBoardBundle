<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Text;

use function htmlspecialchars;
use function nl2br;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Since 1.19: the default {@see TaskTextRendererInterface} — the text escaped, its line breaks as `<br>`.
 */
final readonly class PlainTaskTextRenderer implements TaskTextRendererInterface
{
    public function render(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
