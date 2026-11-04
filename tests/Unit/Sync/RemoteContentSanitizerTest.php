<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use League\CommonMark\CommonMarkConverter;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('`<b>code</b>`', $sanitizer->markdown('`<b>code</b>`', 1000));
        self::assertSame("```\n<img src=x onerror=1>\n```", $sanitizer->markdown("```\n<img src=x onerror=1>\n```", 1000));
        self::assertSame("~~~ php\n<?php echo 1;\n~~~", $sanitizer->markdown("~~~ php\n<?php echo 1;\n~~~", 1000));
        self::assertSame("a\n```\n<b>never closed", $sanitizer->markdown("a\n```\n<b>never closed", 1000), 'an unclosed fence runs to the end');
        self::assertSame('[ok](https://example.com "t") and [m](mailto:a@example.com)', $sanitizer->markdown('[ok](https://example.com "t") and [m](mailto:a@example.com)', 1000));
        self::assertSame("[r]: https://example.com\n\n[x][r]", $sanitizer->markdown("[r]: https://example.com\n\n[x][r]", 1000));
        self::assertSame('``a ` b`` and \\`plain', $sanitizer->markdown('``a ` b`` and \\`plain', 1000));
        self::assertSame('a \\<b and 1 < 2', $sanitizer->markdown('a <b and 1 < 2', 1000));
    }
}
