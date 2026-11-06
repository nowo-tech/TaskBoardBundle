<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function file_get_contents;

/**
 * Since 1.28.0: the `btn_danger` slot alone decides the colours. The class sits on top of the slot, so a colour here gave
 * red on red with a solid slot (Beacon, `_edit_column_modal`, `_task_links_modal`, `_task_members_modal`).
 */
final class DangerButtonCssTest extends TestCase
{
    public function testTheClassSetsNoColourOverTheSlot(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../src/Resources/public/css/task-board.css');
        self::assertDoesNotMatchRegularExpression('/\.nowo-task-btn-danger[^{]*\{[^}]*(?:color|border-color)\s*:/', $css);
    }
}
