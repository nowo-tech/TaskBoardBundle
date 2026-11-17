<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Tests\Stub\TestUser;
use Nowo\TaskBoardBundle\Text\TaskTextRendererInterface;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use PHPUnit\Framework\TestCase;

/**
 * Since 2.22.0 (UiKit 2.54 SECURITY): the HTML a host's text renderer returns for user text — a task description, a
 * comment, a Markdown board description — never reaches the page with UiKit's context-menu attributes or the card marker,
 * even when the host's sanitizer keeps `data-*`; the comment's mention chips (the bundle's own markup) stay.
 */
final class UserHtmlReservedAttributesTest extends TestCase
{
    public const HOSTILE = '<p data-nowo-ui-context-menu="admin-kebab" data-x="1">a</p><div data-nowo-ui-card data-nowo-ui-context-menu-scope>b</div>';

    public function testDescriptionsCommentsAndBoardDescriptionsAreStripped(): void
    {
        $twig  = new TaskBoardTwigExtension(textRenderer: $this->renderer());
        $user  = new TestUser('u1', 'ana');
        $board = (new TaskBoard('B', 'b', $user))->setDescription('x')->setDescriptionFormat(TaskBoard::FORMAT_MARKDOWN);
        $task  = new Task($board, 'T', $user);

        $expected = '<p data-x="1">a</p><div>b</div>';
        self::assertSame($expected, $twig->renderText('anything'));
        self::assertSame($expected, $twig->boardDescription($board));
        self::assertSame($expected, $twig->renderCommentBody(new TaskComment($task, $user, 'anything')));
        self::assertSame('', $twig->renderText(' '));
    }

    private function renderer(): TaskTextRendererInterface
    {
        return new class implements TaskTextRendererInterface {
            public function render(string $text): string
            {
                return UserHtmlReservedAttributesTest::HOSTILE;
            }
        };
    }
}
