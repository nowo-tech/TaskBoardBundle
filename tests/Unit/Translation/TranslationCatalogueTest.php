<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function in_array;
use function is_array;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Guards against untranslated strings: in every non-en catalogue (all domains) a key must exist, and a value identical to
 * the English one (any length, ignoring values without letters once placeholders are removed) must be listed in
 * ALLOWED_IDENTICAL for that locale. An allow-list entry that is no longer identical to English is reported as stale.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../src/Resources/translations';

    /**
     * Keys that are correctly identical to English in a locale (brands, product and technical names, codes, loanwords
     * and cognates), per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_IDENTICAL = [
        'de' => [
            'nav.brand',
            'task_board.board.title',
            'task_board.copy.target',
            'task_board.form.board.slug',
            'task_board.form.link.url',
            'task_board.form.task.start',
            'task_board.form.task.tags',
            'task_board.import.help.clickup_title',
            'task_board.import.source.clickup_csv',
            'task_board.import.source.clickup_json',
            'task_board.import.source.jira_csv',
            'task_board.import.source.trello_json',
            'task_board.link.issue',
            'task_board.link.url',
            'task_board.member.stakeholder',
            'task_board.menu.status',
            'task_board.multi.column.person',
            'task_board.multi.export.labels',
            'task_board.multi.export.status',
            'task_board.multi.filter.label',
            'task_board.multi.filter.status',
            'task_board.priority.normal',
            'task_board.question.form.context_help',
            'task_board.question.form.option',
            'task_board.question.mine.column.board',
            'task_board.search.group.boards',
            'task_board.sync.details.status',
            'task_board.sync.details.tags',
            'task_board.sync.field.column',
            'task_board.sync.field.tags',
            'task_board.sync.history.details',
            'task_board.sync.history.status',
            'task_board.sync.provider.clickup',
            'task_board.task.links',
            'task_board.task.status',
            'task_board.task.workflow',
        ],
        'es' => [
            'nav.brand',
            'task_board.form.board.slug',
            'task_board.form.column.color',
            'task_board.form.link.url',
            'task_board.import.help.clickup_title',
            'task_board.import.source.clickup_csv',
            'task_board.import.source.clickup_json',
            'task_board.import.source.jira_csv',
            'task_board.import.source.trello_json',
            'task_board.link.issue',
            'task_board.link.pull_request',
            'task_board.link.url',
            'task_board.member.stakeholder',
            'task_board.multi.export.no',
            'task_board.priority.normal',
            'task_board.sync.details.minutes',
            'task_board.sync.fields.no',
            'task_board.sync.provider.clickup',
            'task_board.sync.trigger.manual',
        ],
        'fr' => [
            'nav.brand',
            'task_board.custom_field.filter.operator',
            'task_board.dependency.form.type',
            'task_board.form.board.description',
            'task_board.form.board.slug',
            'task_board.form.link.url',
            'task_board.form.task.description',
            'task_board.history.channel.mcp',
            'task_board.import.form.source',
            'task_board.import.help.clickup_title',
            'task_board.import.source.clickup_csv',
            'task_board.import.source.clickup_json',
            'task_board.import.source.jira_csv',
            'task_board.import.source.trello_json',
            'task_board.link.documentation',
            'task_board.link.merge_request',
            'task_board.link.pull_request',
            'task_board.link.url',
            'task_board.list.title',
            'task_board.menu.column',
            'task_board.question.form.option',
            'task_board.question.form.options',
            'task_board.question.form.text',
            'task_board.question.mine.column.question',
            'task_board.question.options',
            'task_board.question.section',
            'task_board.recurrence.status.active',
            'task_board.sync.details.minutes',
            'task_board.sync.details.points',
            'task_board.sync.field.description',
            'task_board.sync.provider.clickup',
            'task_board.sync.trigger.console',
            'task_board.task.dates',
            'task_board.task.description_section',
            'task_board.task.documentation',
            'task_board.time.form.date',
            'task_board.time.form.minutes',
            'task_board.time.form.note',
        ],
        'it' => [
            'nav.brand',
            'task_board.form.board.slug',
            'task_board.form.link.url',
            'task_board.form.task.tags',
            'task_board.history.channel.api',
            'task_board.history.channel.app',
            'task_board.history.channel.mcp',
            'task_board.history.channel.web',
            'task_board.import.help.clickup_title',
            'task_board.import.source.clickup_csv',
            'task_board.import.source.clickup_json',
            'task_board.import.source.jira_csv',
            'task_board.import.source.trello_json',
            'task_board.link.attachment',
            'task_board.link.issue',
            'task_board.link.merge_request',
            'task_board.link.pull_request',
            'task_board.link.url',
            'task_board.member.stakeholder',
            'task_board.multi.export.no',
            'task_board.sync.connect.provider',
            'task_board.sync.details.minutes',
            'task_board.sync.fields.no',
            'task_board.sync.provider.clickup',
            'task_board.sync.provider.unknown',
            'task_board.sync.trigger.console',
            'task_board.task.links',
        ],
        'nl' => [
            'nav.brand',
            'task_board.calendar.mode_week',
            'task_board.custom_field.operator.eq',
            'task_board.form.board.slug',
            'task_board.form.link.label',
            'task_board.form.link.url',
            'task_board.form.task.start',
            'task_board.form.task.tags',
            'task_board.history.channel.api',
            'task_board.history.channel.app',
            'task_board.history.channel.mcp',
            'task_board.history.channel.web',
            'task_board.import.help.clickup_title',
            'task_board.import.source.clickup_csv',
            'task_board.import.source.clickup_json',
            'task_board.import.source.jira_csv',
            'task_board.import.source.trello_json',
            'task_board.link.issue',
            'task_board.link.merge_request',
            'task_board.link.pull_request',
            'task_board.link.url',
            'task_board.list.filters',
            'task_board.member.stakeholder',
            'task_board.menu.status',
            'task_board.multi.column.open',
            'task_board.multi.export.labels',
            'task_board.multi.export.start',
            'task_board.multi.export.status',
            'task_board.multi.filter.label',
            'task_board.multi.filter.status',
            'task_board.multi.status.open',
            'task_board.priority.urgent',
            'task_board.question.form.context',
            'task_board.question.open_count',
            'task_board.sync.comment.open',
            'task_board.sync.connect.provider',
            'task_board.sync.connect.providers',
            'task_board.sync.details.minutes',
            'task_board.sync.details.status',
            'task_board.sync.field.column',
            'task_board.sync.history.details',
            'task_board.sync.history.status',
            'task_board.sync.provider.clickup',
            'task_board.sync.provider.unknown',
            'task_board.sync.trigger.console',
            'task_board.task.links',
            'task_board.task.status',
            'task_board.task.workflow',
        ],
        'pt' => [
            'nav.brand',
            'task_board.form.board.slug',
            'task_board.form.link.url',
            'task_board.history.channel.api',
            'task_board.history.channel.app',
            'task_board.history.channel.mcp',
            'task_board.history.channel.web',
            'task_board.import.help.clickup_title',
            'task_board.import.source.clickup_csv',
            'task_board.import.source.clickup_json',
            'task_board.import.source.jira_csv',
            'task_board.import.source.trello_json',
            'task_board.link.issue',
            'task_board.link.merge_request',
            'task_board.link.pull_request',
            'task_board.link.url',
            'task_board.priority.normal',
            'task_board.sync.details.minutes',
            'task_board.sync.provider.clickup',
            'task_board.sync.trigger.manual',
        ],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        $files = glob(self::DIR . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match('/^(.+)\.([a-z]{2})\.yaml$/', basename($file), $m) === 1 && $m[2] !== 'en') {
                yield basename($file) => [$m[1], $m[2]];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testLocaleHasEveryEnglishKey(string $domain, string $locale): void
    {
        $missing = array_values(array_diff(array_keys($this->load($domain, 'en')), array_keys($this->load($domain, $locale))));

        self::assertSame([], $missing, sprintf('Keys of %s.en.yaml missing in %s.%s.yaml', $domain, $domain, $locale));
    }

    #[DataProvider('catalogues')]
    public function testNoUntranslatedEnglishValues(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_IDENTICAL[$locale] ?? [];
        $gaps    = [];

        foreach ($english as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || in_array($key, $allowed, true)) {
                continue;
            }

            if (preg_match('/[^\W\d_]/u', (string) preg_replace('/%[^%\s]+%|\{[^}]+\}/', '', $value)) === 1) {
                $gaps[] = $key;
            }
        }

        self::assertSame([], $gaps, sprintf('Values identical to English in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    public function testAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_IDENTICAL as $locale => $keys) {
            foreach ($keys as $key) {
                if (!isset($english[$key]) || ($locales[$locale][$key] ?? null) !== $english[$key]) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Allow-list entries that no longer exist or are no longer identical to English');
    }

    /** @return array<string, string> */
    private function load(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }
}
