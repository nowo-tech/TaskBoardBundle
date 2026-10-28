<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Translation;

use Nowo\TaskBoardBundle\Enum\GanttScale;
use Nowo\TaskBoardBundle\Enum\TaskChangeType;
use Nowo\TaskBoardBundle\Enum\TaskLinkType;
use Nowo\TaskBoardBundle\Enum\TaskMemberRole;
use Nowo\TaskBoardBundle\Enum\TaskPriority;
use Nowo\TaskBoardBundle\Exception\InvalidTaskDependencyException;
use Nowo\TaskBoardBundle\Import\TaskImportSource;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

use function is_array;
use function sprintf;

/**
 * Every translation key literally used in PHP/Twig (plus enum-driven dynamic keys) must exist in every shipped locale.
 */
final class TranslationKeysTest extends TestCase
{
    private const LOCALES = ['en', 'es', 'de', 'fr', 'it', 'nl', 'pt'];

    private const SRC = __DIR__ . '/../../../src';

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryUsedKeyExistsInLocale(string $locale): void
    {
        $catalogue = $this->catalogue($locale);
        $missing   = array_values(array_diff($this->usedKeys(), array_keys($catalogue)));

        self::assertSame([], $missing, sprintf('Missing keys in NowoTaskBoardBundle.%s.yaml', $locale));
    }

    #[DataProvider('locales')]
    public function testLocaleHasSameKeysAsEnglishAndNoEmptyValues(string $locale): void
    {
        $catalogue = $this->catalogue($locale);
        $english   = $this->catalogue('en');

        self::assertSame([], array_values(array_diff(array_keys($english), array_keys($catalogue))), 'Keys missing compared to en');
        self::assertSame([], array_values(array_diff(array_keys($catalogue), array_keys($english))), 'Extra keys compared to en');

        foreach ($catalogue as $key => $value) {
            self::assertNotSame('', trim($value), sprintf('%s is empty in %s', $key, $locale));
        }
    }

    public function testUsedKeyScanFindsTemplateAndControllerKeys(): void
    {
        $keys = $this->usedKeys();

        self::assertContains('task_board.gantt.title', $keys);
        self::assertContains('task_board.flash.column_updated', $keys);
        self::assertContains('task_board.history.type.' . TaskChangeType::MemberRemoved->value, $keys);
        self::assertNotContains('task_board.priority.', $keys, 'Concatenation prefixes are not keys');
    }

    /** @return list<string> */
    private function usedKeys(): array
    {
        $keys     = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') && !str_ends_with($path, '.twig')) {
                continue;
            }

            preg_match_all('/[\'"](task_board\.[a-z0-9_.]*[a-z0-9_])[\'"]/', (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        // Keys built in templates from enum values ('task_board.priority.' ~ priority.value, …).
        $dynamic = [
            'task_board.priority.'     => TaskPriority::cases(),
            'task_board.member.'       => TaskMemberRole::cases(),
            'task_board.link.'         => TaskLinkType::cases(),
            'task_board.history.type.' => TaskChangeType::cases(),
        ];
        foreach ($dynamic as $prefix => $cases) {
            foreach ($cases as $case) {
                $keys[$prefix . $case->value] = true;
            }
        }

        // Since 1.15: keys built from the scales, the dependency refusals and relations, the deviation statuses.
        $suffixes = [
            'task_board.gantt.scale_'         => array_map(static fn (GanttScale $scale): string => $scale->value, GanttScale::cases()),
            'task_board.gantt.clamped_'       => array_map(static fn (GanttScale $scale): string => $scale->value, GanttScale::cases()),
            'task_board.flash.dependency_'    => [InvalidTaskDependencyException::REASON_SELF, InvalidTaskDependencyException::REASON_OTHER_BOARD, InvalidTaskDependencyException::REASON_DUPLICATE, InvalidTaskDependencyException::REASON_CYCLE],
            'task_board.dependency.relation.' => ['blocks', 'blocked_by', 'related', 'duplicates', 'duplicated_by'],
            'task_board.time.status.'         => [TaskDeviation::STATUS_NONE, TaskDeviation::STATUS_ON_TRACK, TaskDeviation::STATUS_OVER, TaskDeviation::STATUS_UNDER],
        ];
        foreach ($suffixes as $prefix => $values) {
            unset($keys[$prefix]);
            foreach ($values as $value) {
                $keys[$prefix . $value] = true;
            }
        }

        foreach (TaskImportSource::cases() as $source) {
            $keys[$source->labelKey()] = true;
        }

        $list = array_keys($keys);
        sort($list);

        return $list;
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $data = Yaml::parseFile(self::SRC . '/Resources/translations/NowoTaskBoardBundle.' . $locale . '.yaml');
        self::assertIsArray($data);

        return self::flatten($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');

                continue;
            }

            $flat[$prefix . $key] = (string) $value;
        }

        return $flat;
    }
}
