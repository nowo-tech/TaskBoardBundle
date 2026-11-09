<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Support;

use Nowo\TaskBoardBundle\DependencyInjection\Configuration;
use Nowo\TaskBoardBundle\Support\IconNames;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function file;
use function file_get_contents;
use function in_array;
use function preg_match_all;
use function sort;
use function str_starts_with;
use function substr;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

/**
 * Since 2.9.0 (audit JI-L3): under UiKit's `bootstrap-icons` set every icon the bundle draws is a real Bootstrap Icons 1.13 name;
 * the other sets get the Tabler names as before.
 */
final class IconNamesTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../../src/Resources/views';

    /** Names the templates pick at run time (ternaries, defaults, view lists), besides the literal `name: '…'` ones. */
    private const DYNAMIC = ['arrow-left', 'x', 'plus', 'building', 'layout-grid', 'folder', 'refresh', 'plug-connected', 'circle', 'circle-check', 'chevron-left', 'chevron-right', 'layout-kanban', 'list', 'chart-bar', 'calendar', 'upload'];

    public function testTheMapGoesFromMissingNamesToRealOnes(): void
    {
        $bootstrap = $this->bootstrapIcons();
        foreach (IconNames::BOOTSTRAP as $tabler => $name) {
            self::assertNotContains($tabler, $bootstrap, $tabler . ' is a Bootstrap icon: a host name would be changed');
            self::assertContains($name, $bootstrap, $tabler . ' → ' . $name);
        }
    }

    public function testEveryBundleIconExistsInBootstrapIcons(): void
    {
        $bootstrap = $this->bootstrapIcons();
        $names     = [...$this->templateIconNames(), ...self::DYNAMIC, ...array_map(static fn (string $id): string => substr($id, 7), Configuration::DEFAULT_COLUMN_ICONS)];
        self::assertGreaterThan(40, count(array_unique($names)));
        $missing = array_values(array_unique(array_filter($names, static fn (string $name): bool => !in_array(IconNames::forSet($name, 'bootstrap-icons'), $bootstrap, true))));
        self::assertSame([], $missing);
    }

    public function testOtherSetsAndHostIdsKeepTheirNames(): void
    {
        self::assertSame('floppy', IconNames::forSet('device-floppy', 'bootstrap-icons'));
        self::assertSame('pencil', IconNames::forSet('pencil', 'bootstrap-icons'));
        self::assertSame('device-floppy', IconNames::forSet('device-floppy', 'tabler-icons'));
        self::assertSame('device-floppy', IconNames::forSet('device-floppy', 'fontawesome'));

        self::assertSame('check-circle', IconNames::columnIcon('tabler:circle-check', 'bootstrap-icons'));
        self::assertSame('check-circle', IconNames::columnIcon('circle-check', 'bootstrap-icons'));
        self::assertSame('circle-check', IconNames::columnIcon('lucide:circle-check', 'bootstrap-icons'), 'a host set is left alone');
        self::assertSame('circle-check', IconNames::columnIcon('tabler:circle-check', 'tabler-icons'));

        self::assertSame('kanban', TaskBoardPages::header(['title' => 'x', 'actions' => [['href' => '/b', 'label' => 'B', 'icon' => 'layout-kanban']]], 'bootstrap-icons')->actions[0]->icon);
        self::assertSame('bar-chart', TaskBoardPages::header(['title' => 'x', 'tabs' => [['href' => '/g', 'label' => 'G', 'icon' => 'chart-bar']]], 'bootstrap-icons')->tabs?->items[0]->icon);
        self::assertSame('layout-kanban', TaskBoardPages::header(['title' => 'x', 'actions' => [['href' => '/b', 'label' => 'B', 'icon' => 'layout-kanban']]])->actions[0]->icon);
        self::assertSame('tabler:layout-kanban', TaskBoardPages::header(['title' => 'x', 'actions' => [['href' => '/b', 'label' => 'B', 'icon' => 'layout-kanban']]], 'ux_icon')->actions[0]->icon);
    }

    /** @return list<string> */
    private function bootstrapIcons(): array
    {
        $lines = file(__DIR__ . '/../../Fixtures/bootstrap-icons-1.13.1.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_values(array_filter($lines, static fn (string $line): bool => !str_starts_with($line, '#')));
    }

    /** @return list<string> the literal icon names of the bundle's templates (`_icon` includes and header `icon:` entries) */
    private function templateIconNames(): array
    {
        $names = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::VIEWS, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            preg_match_all("/(?:_icon\\.html\\.twig', \\{name: |icon: )'([a-z0-9-]+)'(?! ~)/", (string) file_get_contents($file->getPathname()), $matches);
            $names = [...$names, ...$matches[1]];
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
