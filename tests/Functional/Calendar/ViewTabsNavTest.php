<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Functional\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;

use function preg_match;
use function preg_match_all;

/**
 * Since 2.8.1: the Gantt scale tabs and the Calendar mode tabs (UiKit `tabs` partial) are named by their own label
 * (`aria_label`, not the partial's "Sections" default) and render one `class` attribute that holds both UiKit's tab
 * classes and the bundle's hook class (UiKit 2.42.1 merges `attr.class`; before, it printed a second `class`).
 */
final class ViewTabsNavTest extends CalendarTestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function views(): iterable
    {
        yield 'gantt scales' => ['/gantt', 'Scale', 'nowo-task-gantt__scales'];
        yield 'calendar modes' => ['/calendar', 'Calendar mode', 'nowo-task-cal__modes'];
    }

    #[DataProvider('views')]
    public function testTheTabsNavIsNamedByItsOwnLabel(string $view, string $name, string $hook): void
    {
        self::assertStringNotContainsString('aria-label="Sections"', $this->navTag($view, $name));
    }

    #[DataProvider('views')]
    public function testTheTabsNavHasOneClassAttributeWithBothClasses(string $view, string $name, string $hook): void
    {
        $tag = $this->navTag($view, $name);
        self::assertSame(1, preg_match_all('/\sclass="/', $tag), $tag);
        self::assertSame(1, preg_match('/\sclass="([^"]*)"/', $tag, $class));
        self::assertMatchesRegularExpression('/(^|\s)nowo-ui-tabs(\s|$)/', $class[1]);
        self::assertMatchesRegularExpression('/(^|\s)' . $hook . '(\s|$)/', $class[1]);
    }

    /** The opening `<nav …>` tag named $name on the board view $view. */
    private function navTag(string $view, string $name): string
    {
        $board = $this->board();
        $this->login($this->owner);
        $this->client->request('GET', '/tools/task-board/board/' . $board->getId() . $view);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, preg_match('/<nav\b[^>]*\saria-label="' . $name . '"[^>]*>/', $html, $m), $view . ': no nav named "' . $name . '"');

        return $m[0];
    }
}
