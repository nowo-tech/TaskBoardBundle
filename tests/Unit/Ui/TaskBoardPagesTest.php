<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Ui;

use LogicException;
use Nowo\TaskBoardBundle\Ui\TaskBoardPages;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TaskBoardPagesTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            'canonical.html.twig' => '{% block nowo_ui_page_header %}{% endblock %}',
            'old.html.twig'       => '{% block body %}{% endblock %}',
        ]));
    }

    public function testNoLayoutAndTheRemovedDefaultMeanTheKitLayout(): void
    {
        foreach ([null, '', TaskBoardPages::LEGACY_DEFAULT_LAYOUT] as $configured) {
            $pages = new TaskBoardPages($configured);
            self::assertNull($pages->layout($this->twig()));
        }
    }

    public function testACanonicalLayoutIsUsedAsIs(): void
    {
        $pages = new TaskBoardPages('canonical.html.twig');
        self::assertSame('canonical.html.twig', $pages->layout($this->twig()));
    }

    /** Since 3.0.0: the 2.0 bridge for a layout without the canonical blocks is gone; such a layout fails, naming it. */
    public function testAnOldLayoutFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the layout "old.html.twig" (web_ui.layout_template) renders no UiKit canonical block');

        (new TaskBoardPages('old.html.twig'))->layout($this->twig());
    }

    public function testALayoutThatDoesNotLoadIsLeftToTwigToReport(): void
    {
        self::assertSame('missing.twig', (new TaskBoardPages('missing.twig'))->layout($this->twig()));
    }

    public function testTheHeaderTakesActionsCrumbAndTheHostsWayBack(): void
    {
        $header = TaskBoardPages::header([
            'title'   => 'Board',
            'intro'   => 'Intro',
            'crumb'   => ['href' => '/boards', 'label' => 'Boards'],
            'actions' => [
                ['href' => '/list', 'label' => 'List', 'icon' => 'list'],
                ['label' => 'New', 'variant' => 'primary', 'dialog' => 'x-new', 'attributes' => ['data-testid' => 'new']],
            ],
            'back' => '/dashboard',
        ], 'ux_icon');

        self::assertSame('Board', $header->title);
        self::assertSame('Intro', $header->intro);
        self::assertSame('own', $header->breadcrumbsMode);
        self::assertSame('Boards', $header->breadcrumbs[0]->label);
        self::assertSame('tabler:list', $header->actions[0]->icon);
        self::assertSame('x-new', $header->actions[1]->dialogId);
        self::assertSame(['data-testid' => 'new'], $header->actions[1]->attributes);
        self::assertSame('/dashboard', $header->actions[2]->href);
        self::assertInstanceOf(Label::class, $header->actions[2]->label);

        self::assertSame('list', TaskBoardPages::header(['title' => 'x', 'actions' => [['href' => '/l', 'label' => 'L', 'icon' => 'list']]])->actions[0]->icon);
        self::assertSame([], TaskBoardPages::header(['title' => 'x'])->actions);
    }
}
