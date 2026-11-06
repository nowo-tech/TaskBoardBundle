<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use League\CommonMark\CommonMarkConverter;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function getenv;
use function mt_rand;
use function mt_srand;
use function preg_match;

/**
 * Since 1.23 (audit EG-L3): what the sanitizer keeps is rendered by league/commonmark with its defaults (raw HTML and
 * unsafe links allowed) and with `html_input: escape`, and must not carry a script link or an HTML element with an
 * event handler — whatever the link label looks like and however the code spans are laid out.
 */
final class RemoteContentSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function payloads(): iterable
    {
        // The 5 bypasses of Appendix EG.
        yield 'brackets in the label' => ['[a [b] c](javascript:alert(document.domain))'];
        yield 'a new line in the label' => ["[click\nhere](javascript:alert(document.domain))"];
        yield 'a reference target on the next line' => ["[x][r]\n\n[r]:\n  javascript:alert(document.domain)"];
        yield 'an escaped backtick' => ['\\`<img src=x onerror=alert(document.domain)>`'];
        yield 'an unclosed inline fence' => ['x ```<img src=x onerror=alert(document.domain)>'];
        // Neighbours of the same rules.
        yield 'HTML that starts before a backtick' => ['<img src="`" onerror=alert(document.domain)>`'];
        yield 'a code span across paragraphs' => ["`a\n\n<img src=x onerror=alert(document.domain)>`"];
        yield 'an unclosed declaration' => ["<!X\n<img src=x onerror=alert(document.domain)\n"];
        yield 'an angle-bracket target' => ['[x](<javascript:alert(document.domain)>)'];
        yield 'a target after new lines' => ["[x](\n  JaVaScRiPt:alert(document.domain))"];
        yield 'an image' => ['![x](javascript:alert(document.domain))'];
        yield 'an autolink' => ['<javascript:alert(document.domain)>'];
        yield 'a processing instruction' => ["<?x\n<img src=x onerror=alert(document.domain)>\n"];
    }

    #[DataProvider('payloads')]
    public function testNothingUnsafeSurvivesTheRenderer(string $payload): void
    {
        $markdown = (new RemoteContentSanitizer())->markdown($payload, 10_000);
        foreach ([[], ['html_input' => 'escape']] as $config) {
            $html = (string) (new CommonMarkConverter($config))->convert($markdown);
            self::assertDoesNotMatchRegularExpression('/(href|src)="\s*javascript:/i', $html, $markdown);
            self::assertDoesNotMatchRegularExpression('/<(img|script|svg|iframe)\b[^>]*\son\w+\s*=/i', $html, $markdown);
        }
    }

    public function testCodeAndSafeLinksAreKept(): void
    {
        $sanitizer = new RemoteContentSanitizer();
        // An inline code span is not trusted to hide HTML (its extent depends on the blocks): its `<` shows a backslash.
        self::assertSame('`\\<b>code\\</b>`', $sanitizer->markdown('`<b>code</b>`', 1000));
        self::assertSame("```\n<img src=x onerror=1>\n```", $sanitizer->markdown("```\n<img src=x onerror=1>\n```", 1000));
        self::assertSame("~~~ php\n<?php echo 1;\n~~~", $sanitizer->markdown("~~~ php\n<?php echo 1;\n~~~", 1000));
        self::assertSame("a\n```\n<b>never closed", $sanitizer->markdown("a\n```\n<b>never closed", 1000), 'an unclosed fence runs to the end');
        self::assertSame('[ok](https://example.com "t") and [m](mailto:a@example.com)', $sanitizer->markdown('[ok](https://example.com "t") and [m](mailto:a@example.com)', 1000));
        self::assertSame("[r]: https://example.com\n\n[x][r]", $sanitizer->markdown("[r]: https://example.com\n\n[x][r]", 1000));
        self::assertSame('``a ` b`` and \\`plain', $sanitizer->markdown('``a ` b`` and \\`plain', 1000));
        self::assertSame("a\n  ```\n\\<b>indented: prose", $sanitizer->markdown("a\n  ```\n<b>indented: prose", 1000));
        self::assertSame('a \\<b and 1 \\< 2', $sanitizer->markdown('a <b and 1 < 2', 1000));
        // Without a fixed point within the rounds, the text is escaped as plain text.
        self::assertSame('\\<b\\> \\[a\\]\\(x\\)', (new RemoteContentSanitizer(maxRounds: 1))->markdown('<b> [a](x)', 1000));
    }

    /**
     * Audit EG-L3 (delta): the auditor's hand cases and differential fuzz — the sanitized text rendered by
     * league/commonmark with raw HTML and unsafe links allowed (the worst host renderer) holds no script element,
     * event handler or script link. SANITIZER_FUZZ_ITERATIONS runs a longer fuzz locally.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function auditorCases(): iterable
    {
        yield 'escaped-run-then-span' => ['\\``a`<img src=x onerror=alert(1)>`'];
        yield 'escaped-run-2' => ['\\```a``<img src=x onerror=alert(1)>``'];
        yield 'refdef-code-label' => ["[y][`x`]\n\n[`x`]: javascript:alert(1)"];
        yield 'refdef-code-label-2' => ["[`x`]: javascript:alert(1)\n\n[`x`]"];
        yield 'refdef-escaped-label' => ["[a\\]b]: javascript:alert(1)\n\n[a\\]b]"];
        yield 'refdef-title-line' => ["[r]:\njavascript:alert(1)\n\"t\"\n\n[r]"];
        yield 'refdef-in-list' => ["- [r]: javascript:alert(1)\n\n[r]"];
        yield 'refdef-in-quote' => ["> [r]: javascript:alert(1)\n\n[r]"];
        yield 'refdef-4sp-cont' => ["[r]: \n    javascript:alert(1)\n\n[r]"];
        yield 'refdef-label-multiline' => ["[r\nx]: javascript:alert(1)\n\n[r x]"];
        yield 'link-angle-space' => ['[a](<javascript:alert(1)>)'];
        yield 'link-ws-tab' => ["[a](\tjavascript:alert(1))"];
        yield 'link-paren-balanced' => ['[a](javascript:alert(1)(x))'];
        yield 'link-escaped-paren' => ['[a](javascript:alert\\(1\\))'];
        yield 'link-escape-colon' => ['[a](javascript\\:alert(1))'];
        yield 'link-entity' => ['[a](javascript&#x3A;alert(1))'];
        yield 'link-uppercase' => ['[a](JaVaScRiPt:alert(1))'];
        yield 'link-code-split' => ['[a](java`x`script:alert(1))'];
        yield 'link-code-before' => ['[`a`](javascript:alert(1))'];
        yield 'image' => ['![a](javascript:alert(1))'];
        yield 'fence-tilde-html' => ["~~~\n~~~\n<img src=x onerror=alert(1)>"];
        yield 'fence-info-backtick' => ["``` a`b\n<img src=x onerror=alert(1)>\n```"];
        yield 'fence-in-list' => ["- ```\n<img src=x onerror=alert(1)>"];
        yield 'fence-indent4' => ["    ```\n<img src=x onerror=alert(1)>"];
        yield 'fence-close-short' => ["````\nx\n```\n<img src=x onerror=alert(1)>"];
        yield 'fence-tab' => ["\t```\n<img src=x onerror=alert(1)>"];
        yield 'span-multiline' => ["`a\n<img src=x onerror=alert(1)>`"];
        yield 'html-block-script' => ["<script\nalert(1)</script>"];
        yield 'html-comment-span' => ['<!-- `x --><img src=x onerror=alert(1)>`'];
        yield 'html-pi' => ['<?php `x ?><img src=x onerror=alert(1)>`'];
        yield 'html-decl' => ['<!X `><img src=x onerror=alert(1)>`'];
        yield 'cdata' => ['<![CDATA[ ` ]]><img src=x onerror=alert(1)>`'];
        yield 'attr-backtick' => ['<a title="`" href="javascript:alert(1)">x</a>`'];
        yield 'nul' => ["<im\0g src=x onerror=alert(1)>"];
        yield 'backslash-lt' => ['\\<img src=x onerror=alert(1)>'];
        yield 'double-backslash-lt' => ['\\\\<img src=x onerror=alert(1)>'];
        yield 'autolink' => ['<javascript:alert(1)>'];
        yield 'autolink-upper' => ['<JAVASCRIPT:alert(1)>'];
        yield 'entity-lt' => ['&lt;img src=x onerror=alert(1)&gt;'];
        yield 'html-in-label' => ['[<img src=x onerror=alert(1)>](https://a)'];
    }

    #[DataProvider('auditorCases')]
    public function testTheAuditorsCasesRenderSafely(string $payload): void
    {
        $html = (string) $this->worstRenderer()->convert((new RemoteContentSanitizer())->markdown($payload, 100_000));
        self::assertFalse($this->unsafe($html), $html);
    }

    public function testTheDifferentialFuzzFindsNothing(): void
    {
        $tokens = ['`', '``', '```', '~~~', '\\', '\\\\', '<img src=x onerror=alert(1)>', '<img', ' src=x onerror=alert(1)', '>', '<', '[a]', '[', ']', '](', '(', ')',
            'javascript:alert(1)', '[r]:', '[r]', '[`r`]:', '[`r`]', ': ', "\n", "\n\n", ' ', '    ', "\t", 'a', '*', '_', '!', '- ', '> ', '1. ', '"t"',
            '<!--', '-->', '<?', '?>', '<![CDATA[', ']]>', '<div>', '</div>', '<!X', '&#58;', '<javascript:alert(1)>', '<a href="javascript:alert(1)">', '</a>'];
        $iterations = (int) (getenv('SANITIZER_FUZZ_ITERATIONS') ?: 3000);
        $sanitizer  = new RemoteContentSanitizer();
        $renderer   = $this->worstRenderer();
        $failing    = [];
        mt_srand((int) (getenv('SANITIZER_FUZZ_SEED') ?: 41));
        for ($k = 0; $k < $iterations; ++$k) {
            $input = '';
            for ($j = mt_rand(2, 14); $j > 0; --$j) {
                $input .= $tokens[mt_rand(0, count($tokens) - 1)];
            }
            if ($this->unsafe((string) $renderer->convert($sanitizer->markdown($input, 100_000)))) {
                $failing[] = $input;
            }
        }

        self::assertSame([], $failing);
    }

    private function worstRenderer(): CommonMarkConverter
    {
        return new CommonMarkConverter(['html_input' => 'allow', 'allow_unsafe_links' => true]);
    }

    private function unsafe(string $html): bool
    {
        return preg_match('/<(img|script|svg|iframe|object|embed|style)\b/i', $html) === 1
            || preg_match('/(href|src)\s*=\s*"\s*(javascript|vbscript|data):/i', $html) === 1
            || preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html) === 1;
    }

    /**
     * Since 1.28.0 (TB-SYNC-4, `markdown_description` of real tasks): a GFM table stays a table; an HTML-like tag stays
     * visible as text, and in a table it does not break the cell.
     */
    public function testTablesAndHtmlLikeTagsOfADescription(): void
    {
        $sanitizer = new RemoteContentSanitizer();
        $table     = "| A | B |\n| --- | --- |\n| 1 | 2 |";
        self::assertSame($table, $sanitizer->markdown($table, 1000));
        self::assertSame("| A |\n| --- |\n| \\<u>x\\</u>\\<br> |", $sanitizer->markdown("| A |\n| --- |\n| <u>x</u><br> |", 1000));
        self::assertSame('Array\\<string> and \\<div class="x">y\\</div>', $sanitizer->markdown('Array<string> and <div class="x">y</div>', 1000));
    }
}
