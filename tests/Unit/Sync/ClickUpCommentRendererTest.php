<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Sync;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Nowo\TaskBoardBundle\Sync\Content\RemoteContentSanitizer;
use Nowo\TaskBoardBundle\Sync\Provider\ClickUp\ClickUpCommentRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Since 1.28.0 (TB-SYNC-4). Shapes: ClickUp's "Comment formatting" page (a Quill delta, block attributes on the line
 * feed); the other reading (the attribute on the text) and undocumented segment types are read defensively.
 */
final class ClickUpCommentRendererTest extends TestCase
{
    /**
     * @param list<array<mixed>> $segments
     */
    private function render(array $segments): string
    {
        return (new RemoteContentSanitizer())->markdown((new ClickUpCommentRenderer())->render($segments), 5000);
    }

    /**
     * @param list<array<mixed>> $segments
     */
    private function html(array $segments): string
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return (new MarkdownConverter($environment))->convert($this->render($segments))->getContent();
    }

    /** The documented shape. */
    public function testBulletListOnTheLineFeed(): void
    {
        self::assertSame("Intro\n\n- one\n- two", $this->render([
            ['text' => 'Intro'],
            ['text' => "\n"],
            ['text' => 'one'],
            ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
            ['text' => 'two'],
            ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
        ]));
    }

    /** The unverified reading: the attribute on the text, and the flat value. */
    public function testBulletListOnTheTextWithAFlatValue(): void
    {
        self::assertSame("- one\n- two", $this->render([
            ['text' => 'one', 'attributes' => ['list' => 'bullet']],
            ['text' => "\n"],
            ['text' => 'two', 'attributes' => ['list' => 'bullet']],
        ]));
    }

    public function testNestedListsByIndentRenderNested(): void
    {
        $segments = [
            ['text' => 'a'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'ordered']]],
            ['text' => 'b'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => 1]],
            ['text' => 'c'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => 2]],
            ['text' => 'd'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'ordered']]],
        ];
        self::assertSame("1. a\n   - b\n     - c\n2. d", $this->render($segments));
        $html = $this->html($segments);
        self::assertSame(1, substr_count($html, '<ol>'));
        self::assertSame(2, substr_count($html, '<ul>'));
        self::assertStringNotContainsString('<pre>', $html);
    }

    /** A first item at indent 3 and a jump of levels never reach four columns of an indented code block. */
    public function testIndentJumpsNeverBecomeCode(): void
    {
        $segments = [
            ['text' => 'x'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => 3]],
            ['text' => 'y'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => 99]],
            ['text' => 'z'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => 1]],
        ];
        self::assertSame("- x\n  - y\n- z", $this->render($segments));
        self::assertStringNotContainsString('<pre>', $this->html($segments));
    }

    public function testChecklistToggleAndKindChange(): void
    {
        self::assertSame("- [x] done\n- [ ] open\n- fold\n1. n\n- back", $this->render([
            ['text' => 'done'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'checked']]],
            ['text' => 'open'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'unchecked']]],
            ['text' => 'fold'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'toggled']]],
            ['text' => 'n'], ['text'    => "\n", 'attributes' => ['list' => ['list' => 'ordered']]],
            ['text' => 'back'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'surprise']]],
        ]));
    }

    public function testIndentedLineUnderAListAndOutsideOne(): void
    {
        self::assertSame("- item\n  under\n\nafter\nplain indented", $this->render([
            ['text' => 'item'], ['text'           => "\n", 'attributes' => ['list' => ['list' => 'bullet']]],
            ['text' => 'under'], ['text'          => "\n", 'attributes' => ['list' => ['list' => 'none'], 'indent' => 1]],
            ['text' => 'after'], ['text'          => "\n"],
            ['text' => 'plain indented'], ['text' => "\n", 'attributes' => ['indent' => 1]],
        ]));
    }

    public function testABlankLineKeepsTheListNumbers(): void
    {
        self::assertSame("1. a\n\n2. b", $this->render([
            ['text' => 'a'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'ordered']]],
            ['text' => "\n"],
            ['text' => 'b'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'ordered']]],
        ]));
    }

    public function testCodeBlockKeepsItsTextRaw(): void
    {
        $segments = [
            ['text' => 'Before'], ['text'                                         => "\n"],
            ['text' => 'if (a < b) {', 'attributes' => ['bold' => true]], ['text' => "\n", 'attributes' => ['code-block' => ['code-block' => 'plain']]],
            ['text' => '  <script>x</script>'], ['text'                           => "\n", 'attributes' => ['code-block' => ['code-block' => 'plain']]],
            ['text' => 'After'],
        ];
        self::assertSame("Before\n\n```\nif (a < b) {\n  <script>x</script>\n```\n\nAfter", $this->render($segments));
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $this->html($segments));
    }

    public function testCodeBlockOnTheTextLanguageAndLongerFence(): void
    {
        self::assertSame("````php\n```\necho 1;\n````", $this->render([
            ['text' => "```\necho 1;", 'attributes' => ['code-block' => 'PHP']],
        ]));
        self::assertSame("```\nx\n```", $this->render([['text' => 'x', 'attributes' => ['code-block' => true]]]));
        self::assertSame("```\nx\n```", $this->render([['text' => 'x', 'attributes' => ['code-block' => 'bad lang!']]]));
        self::assertSame('x', $this->render([['text' => 'x', 'attributes' => ['code-block' => false]]]));
    }

    public function testACodeLineCannotCloseTheFence(): void
    {
        $html = $this->html([['text' => "```\n<img src=x onerror=y>\n```", 'attributes' => ['code-block' => ['code-block' => 'plain']]]]);
        self::assertStringNotContainsString('<img', $html);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function inlineStyles(): iterable
    {
        yield 'bold' => [['bold' => true], '**x**'];
        yield 'italic' => [['italic' => true], '*x*'];
        yield 'code' => [['code' => true], '`x`'];
        yield 'strike' => [['strike' => true], '~~x~~'];
        yield 'strikethrough' => [['strikethrough' => true], '~~x~~'];
        yield 'underline keeps the text' => [['underline' => true], 'x'];
        yield 'colour keeps the text' => [['color' => '#ff0000', 'background-color' => '#00ff00'], 'x'];
        yield 'link' => [['link' => 'https://example.test/a'], '[x](https://example.test/a)'];
        yield 'unsafe link leaves the text' => [['link' => 'javascript:alert(1)'], 'x'];
        yield 'mixed-case unsafe link' => [['link' => 'JaVaScRiPt:alert(1)'], 'x'];
        yield 'control character in the scheme' => [['link' => "java\tscript:alert(1)"], 'x'];
        yield 'data link' => [['link' => 'data:text/html,x'], 'x'];
        yield 'relative link' => [['link' => '/x'], 'x'];
        yield 'not a string' => [['link' => ['x']], 'x'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('inlineStyles')]
    public function testInlineStyles(array $attributes, string $expected): void
    {
        self::assertSame($expected, $this->render([['text' => 'x', 'attributes' => $attributes]]));
    }

    public function testStylesKeepOuterSpacesOutAndSpansSurviveBackticks(): void
    {
        self::assertSame('a **b** c', $this->render([['text' => 'a'], ['text' => ' b ', 'attributes' => ['bold' => true]], ['text' => 'c']]));
        self::assertSame('`` a`b ``', $this->render([['text' => 'a`b', 'attributes' => ['code' => true]]]));
    }

    public function testLinkTargetAndLabelCannotBreakOut(): void
    {
        self::assertSame('[a\\]b](https://x.test/a%20b%29%28%3Cc%3E%22)', $this->render([['text' => 'a]b', 'attributes' => ['link' => 'https://x.test/a b)(<c>"']]]));
    }

    public function testLinkMentionShapes(): void
    {
        self::assertSame('[Title](https://x.test/1)', $this->render([['type' => 'link_mention', 'text' => 'Title', 'link_mention' => ['url' => 'https://x.test/1']]]));
        self::assertSame('[Name](https://x.test/2)', $this->render([['type' => 'link_mention', 'link_mention' => ['link' => 'https://x.test/2', 'name' => 'Name']]]));
        self::assertSame('[https://x.test/3](https://x.test/3)', $this->render([['type' => 'link_mention', 'link_mention' => ['href' => 'https://x.test/3']]]));
        self::assertSame('[https://x.test/4](https://x.test/4)', $this->render([['type' => 'link_mention', 'text' => 'https://x.test/4', 'link_mention' => []]]));
        self::assertSame('[T](https://x.test/5)', $this->render([['type' => 'link_mention', 'text' => 'T', 'attributes' => ['link' => 'https://x.test/5'], 'link_mention' => []]]));
        self::assertSame('[B](https://x.test/6)', $this->render([['type' => 'bookmark', 'bookmark' => ['url' => 'https://x.test/6', 'title' => 'B']]]));
        self::assertSame('plain', $this->render([['type' => 'link_mention', 'text' => 'plain', 'link_mention' => ['url' => 'javascript:x']]]));
        self::assertSame('', $this->render([['type' => 'link_mention', 'link_mention' => []]]));
        // No object for the type: plain text.
        self::assertSame('only text', $this->render([['type' => 'link_mention', 'text' => 'only text']]));
    }

    public function testTaskMentionShapes(): void
    {
        self::assertSame('[Fix it](https://app.clickup.com/t/86a1)', $this->render([['type' => 'task_mention', 'text' => 'Fix it', 'task_mention' => ['task_id' => '86a1']]]));
        self::assertSame('[Named](https://app.clickup.com/t/86a2)', $this->render([['type' => 'task_mention', 'task_mention' => ['id' => '86a2', 'name' => 'Named']]]));
        self::assertSame('[86a3](https://app.clickup.com/t/86a3)', $this->render([['type' => 'task_mention', 'task' => ['id' => '86a3']]]));
        self::assertSame('[T](https://x.test/t)', $this->render([['type' => 'task_mention', 'text' => 'T', 'task_mention' => ['url' => 'https://x.test/t', 'id' => '86a4']]]));
        // A bad id builds no address; the label stays.
        self::assertSame('Label', $this->render([['type' => 'task_mention', 'text' => 'Label', 'task_mention' => ['id' => '../x']]]));
        self::assertSame('Label', $this->render([['type' => 'task_mention', 'text' => 'Label', 'task_mention' => ['id' => 'ok', 'url' => 'javascript:x']]]));
    }

    public function testOtherSegmentsAndNoise(): void
    {
        self::assertSame('😊 ok', $this->render([['text' => 'U0001F60A', 'type' => 'emoticon', 'emoticon' => ['code' => '1f60a']], ['text' => ' ok']]));
        self::assertSame('👨‍💻', $this->render([['type' => 'emoticon', 'emoticon' => ['code' => '1f468-200d-1f4bb']]]));
        self::assertSame('fallback', $this->render([['text' => 'fallback', 'type' => 'emoticon', 'emoticon' => ['code' => 'zz']]]));
        self::assertSame('fallback', $this->render([['text' => 'fallback', 'type' => 'emoticon']]));
        self::assertSame('@Ana hi', $this->render([['type' => 'tag', 'user' => ['id' => 1, 'username' => 'Ana']], ['text' => ' hi']]));
        self::assertSame('@1234', $this->render([['type' => 'tag', 'text' => '@1234', 'user' => ['id' => 1234]]]));
        self::assertSame('kept', $this->render([['type' => 'tag', 'text' => 'kept']]));
        self::assertSame('![pic\\]](https://x.test/p.png)', $this->render([['type' => 'image', 'image' => ['name' => 'pic]', 'url' => 'https://x.test/p.png']]]));
        self::assertSame('', $this->render([['type' => 'image', 'image' => ['url' => 'javascript:x']]]));
        self::assertSame('', $this->render([['type' => 'attachment', 'text' => 'f.pdf', 'attachment' => []]]));
        self::assertSame('x', $this->render(['junk', ['text' => 42], ['text' => 'x', 'attributes' => 'junk'], ['type' => ['no'], 'text' => '']]));
    }

    public function testRareQuillBlocks(): void
    {
        self::assertSame("# H\n\n> q\n\nend", $this->render([
            ['text' => 'H'], ['text' => "\n", 'attributes' => ['header' => ['header' => 1]]],
            ['text' => 'q'], ['text' => "\n", 'attributes' => ['blockquote' => true]],
            ['text' => 'end'],
        ]));
        self::assertSame('## H', $this->render([['text' => 'H', 'attributes' => ['header' => 2]]]));
    }

    public function testASegmentWithSeveralLinesAppliesToEach(): void
    {
        self::assertSame("- a\n- b", $this->render([['text' => "a\nb\n", 'attributes' => ['list' => ['list' => 'bullet']]]]));
    }

    public function testAMarkerlessItemAndHostileIndent(): void
    {
        self::assertSame("- a\n  - b", $this->render([
            ['text' => 'a'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => -4]],
            ['text' => 'b'], ['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet'], 'indent' => 99_999]],
        ]));
        self::assertSame('-', $this->render([['text' => "\n", 'attributes' => ['list' => ['list' => 'bullet']]]]));
        self::assertSame('x', $this->render([['text' => 'x', 'attributes' => ['list' => ['nope' => 1]]]]));
        self::assertSame('x', $this->render([['text' => 'x', 'attributes' => ['list' => '']]]));
    }
}
