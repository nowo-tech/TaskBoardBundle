<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Text;

/**
 * Since 1.19: how task text typed by users (comment bodies, plain-text descriptions) becomes HTML. The default keeps
 * it plain (escaped, line breaks kept); a host may render Markdown or its own markup. The result is printed raw:
 * an implementation MUST escape or sanitize everything the user typed.
 */
interface TaskTextRendererInterface
{
    public function render(string $text): string;
}
