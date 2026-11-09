<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function strpos;
use function strrpos;
use function substr;

/**
 * Since 2.8.0 (host mobile UX audit MUX-70, MUX-73, MUX-74): the phone block (≤ 640px) at the end of task-board.css narrows
 * the Gantt title column, keeps the legend on one row, shrinks the subtask indent and lifts meta texts to 12px. Desktop
 * rules stay as they were.
 */
final class PhoneLayoutCssTest extends TestCase
{
    private static function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/Resources/public/css/task-board.css');
    }

    /** The last `@media (max-width: 640px)` block: the 2.8.0 phone rules, which must come after every other rule. */
    private static function phoneBlock(): string
    {
        $css   = self::css();
        $start = strrpos($css, '@media (max-width: 640px) {');
        self::assertNotFalse($start);

        return substr($css, $start);
    }

    /** The declarations of the first rule whose selector list ends with $selector in $css. */
    private static function rule(string $css, string $selector): string
    {
        self::assertSame(1, preg_match('/(?:^|[\s,}])' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $css, $m), $selector);

        return $m[1];
    }

    public function testThePhoneGanttTitleColumnIs96Px(): void
    {
        self::assertStringContainsString('--gantt-label-width: 6rem;', self::rule(self::phoneBlock(), '.nowo-task-gantt'));
    }

    public function testTheDesktopGanttTitleColumnIsUnchanged(): void
    {
        $css = self::css();
        self::assertStringContainsString('--gantt-label-width: 18rem;', $css);
        self::assertStringContainsString("--gantt-label-width: 10rem;\n        --gantt-day-width: 2.25rem;", $css);
    }

    public function testThePhoneLegendIsOneScrollingRow(): void
    {
        $legend = self::rule(self::phoneBlock(), '.nowo-task-gantt__legend');
        self::assertStringContainsString('flex-wrap: nowrap;', $legend);
        self::assertStringContainsString('overflow-x: auto;', $legend);
        self::assertStringContainsString('white-space: nowrap;', self::rule(self::phoneBlock(), '.nowo-task-gantt__legend li'));
    }

    public function testThePhoneSubtaskIndentStepIsSmaller(): void
    {
        $block = self::phoneBlock();
        self::assertStringContainsString('padding-inline-start: 0.75rem;', self::rule($block, '.nowo-task-subtree [role="group"]'));
        self::assertStringContainsString('padding-inline: 0;', self::rule($block, '.nowo-task-detail__subtask-list [role="group"] .nowo-task-subtree__item'));
        self::assertMatchesRegularExpression('/\.nowo-task-subtree \[role="tree"\]:not\(:has\(> li > \.nowo-ui-tree__row > \.nowo-ui-tree__toggle\)\) > li > \.nowo-ui-tree__row > \.nowo-ui-tree__spacer\s*\{\s*display: none;/', $block);
    }

    public function testNoPhoneMetaTextIsBelow12Px(): void
    {
        $block = self::phoneBlock();
        foreach (['.nowo-task-board__column-count', '.nowo-task-column__empty', '.nowo-task-avatar--more', '.nowo-task-detail__status-badge', '.nowo-task-gantt__month-label', '.nowo-task-gantt__weekday'] as $selector) {
            self::assertNotFalse(strpos($block, $selector), $selector);
        }
        preg_match_all('/font-size:\s*([0-9.]+)rem/', $block, $sizes);
        self::assertNotEmpty($sizes[1]);
        foreach ($sizes[1] as $size) {
            self::assertGreaterThanOrEqual(0.75, (float) $size);
        }
    }
}
