<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Tests\Unit\Twig;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskBoard;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\UiKitBundle\Theme\Theme;
use Nowo\UiKitBundle\Twig\ThemeExtension;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TaskBoardTwigExtensionTest extends TestCase
{
    public function testGetGlobals(): void
    {
        $extension = new TaskBoardTwigExtension();

        self::assertSame([
            'nowo_task_board_filters_collapse'          => null,
            'nowo_task_board_filters_form_class'        => '',
            'nowo_task_board_connection_badge_template' => '@NowoTaskBoardBundle/manage/_connection_badge.html.twig',
            'nowo_task_board_sync_badge_connect_link'   => true,
            'nowo_task_board_sync_badge_style'          => 'badge',
            'nowo_task_board_task_time_before'          => null,
            'nowo_task_board_ui'                        => ['page_header' => true, 'board_toolbar' => true, 'panel_toolbar' => true],
        ], $extension->getGlobals());
    }

    public function testGetGlobalsUsesConfiguredValues(): void
    {
        $extension = new TaskBoardTwigExtension([], new TaskObjectLinkResolver(), 'shared/_filters_collapse.html.twig', 'issue-filters panel');

        self::assertSame([
            'nowo_task_board_filters_collapse'          => 'shared/_filters_collapse.html.twig',
            'nowo_task_board_filters_form_class'        => 'issue-filters panel',
            'nowo_task_board_connection_badge_template' => '@NowoTaskBoardBundle/manage/_connection_badge.html.twig',
            'nowo_task_board_sync_badge_connect_link'   => true,
            'nowo_task_board_sync_badge_style'          => 'badge',
            'nowo_task_board_task_time_before'          => null,
            'nowo_task_board_ui'                        => ['page_header' => true, 'board_toolbar' => true, 'panel_toolbar' => true],
        ], $extension->getGlobals());
    }

    public function testSafeUrlOnlyAllowsHttpAndHttps(): void
    {
        self::assertSame('https://example.com/a?b=1', TaskBoardTwigExtension::safeUrl('https://example.com/a?b=1'));
        self::assertSame('http://gitlab.local/mr/1', TaskBoardTwigExtension::safeUrl(' http://gitlab.local/mr/1 '));
        self::assertSame('HTTPS://EXAMPLE.COM', TaskBoardTwigExtension::safeUrl('HTTPS://EXAMPLE.COM'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl('javascript:alert(document.cookie)'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl(' JavaScript:alert(1)'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl('data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl('//evil.example'));
        self::assertSame('#', TaskBoardTwigExtension::safeUrl(null));
    }

    public function testSafeColorOnlyAllowsHexColours(): void
    {
        self::assertSame('#3b82f6', TaskBoardTwigExtension::safeColor('#3b82f6'));
        self::assertSame('#FFF', TaskBoardTwigExtension::safeColor('#FFF'));
        self::assertSame('#11223344', TaskBoardTwigExtension::safeColor('#11223344'));
        self::assertNull(TaskBoardTwigExtension::safeColor('red;background:url(https://evil.example/x)'));
        self::assertNull(TaskBoardTwigExtension::safeColor("#fff\n"));
        self::assertNull(TaskBoardTwigExtension::safeColor('#12345'));
        self::assertNull(TaskBoardTwigExtension::safeColor(null));
    }

    public function testFiltersAreRegistered(): void
    {
        $names = array_map(static fn (TwigFilter $filter): string => $filter->getName(), (new TaskBoardTwigExtension())->getFilters());

        self::assertSame(['nowo_task_board_safe_url', 'nowo_task_board_safe_color', 'nowo_task_board_tag_list', 'nowo_task_board_duration'], $names);
    }

    public function testAncestorsGoFromTheTopDownAndStopOnCycles(): void
    {
        $user  = new stdClass();
        $board = new TaskBoard('B', 'b', $user);
        $epic  = new Task($board, 'Epic', $user);
        $story = new Task($board, 'Story', $user, parent: $epic);
        $leaf  = new Task($board, 'Leaf', $user, parent: $story);

        self::assertSame([$epic, $story], TaskBoardTwigExtension::ancestors($leaf));
        self::assertSame([], TaskBoardTwigExtension::ancestors($epic));

        (new ReflectionProperty(Task::class, 'parent'))->setValue($epic, $leaf);
        self::assertCount(50, TaskBoardTwigExtension::ancestors($leaf));
    }

    public function testRouteFunctionMapsLogicalKeysToConfiguredNames(): void
    {
        $extension = new TaskBoardTwigExtension([
            'board' => ['path' => '/b/{boardId}', 'name' => 'app_board'],
            'list'  => ['path' => '/l/{boardId}', 'name' => ''],
        ]);

        self::assertSame(['nowo_task_board_route', 'nowo_task_board_link_label', 'nowo_task_board_link_url', 'nowo_task_board_link_badge', 'nowo_task_board_subtask_progress', 'nowo_task_board_deviation', 'nowo_task_board_ancestors', 'nowo_task_board_comment_body', 'nowo_task_board_text', 'nowo_task_board_connection', 'task_board_sync_creator', 'task_board_sync_comment_author', 'task_board_sync_unmatched', 'task_board_sync_token', 'task_board_sync_fields', 'task_board_sync_search_texts', 'task_board_sync_comment_meta', 'task_board_sync_details', 'nowo_task_board_member_avatar_url', 'nowo_task_board_btn_class', 'nowo_task_board_page_layout', 'nowo_task_board_legacy_layout', 'nowo_task_board_legacy_blocks', 'nowo_task_board_header', 'nowo_task_board_subtask_nodes', 'nowo_task_board_list_table', 'nowo_task_board_description', 'nowo_task_board_label', 'nowo_task_board_tenant_labels', 'nowo_task_board_configured_help_link', 'nowo_task_board_number', 'nowo_task_board_numbers_enabled', 'nowo_task_board_calendar_enabled', 'nowo_task_board_calendar_tabs', 'nowo_task_board_can_copy', 'nowo_task_board_recurrence', 'nowo_task_board_repeats', 'nowo_task_board_change_cause', 'nowo_task_board_comment_cause', 'nowo_task_board_icon_name', 'nowo_task_board_column_icon_name', 'nowo_task_board_column_icon_storable'], array_map(static fn (TwigFunction $function): string => $function->getName(), $extension->getFunctions()));
        self::assertSame('app_board', $extension->routeName('board'));
        self::assertSame('nowo_task_board_list', $extension->routeName('list'));
        self::assertSame('nowo_task_board_task', $extension->routeName('task'));
    }

    public function testLinkBadgeAndSubtaskProgress(): void
    {
        $extension = new TaskBoardTwigExtension();
        $board     = new TaskBoard('Board', 'board', new stdClass());
        $task      = new Task($board, 'Parent', new stdClass());
        $subtask   = new Task($board, 'Child', new stdClass(), parent: $task);
        $task->getSubtasks()->add($subtask);
        $subtask->markCompleted();

        self::assertNull($extension->linkBadge(TaskLink::forHostObject($task, 'issue', '1')));
        self::assertSame(1, $extension->subtaskProgress($task)->done);
        self::assertSame(1, $extension->subtaskProgress($task)->total);
    }

    public function testTagListDecodesJsonListsOfStrings(): void
    {
        self::assertSame(['api', 'ui'], TaskBoardTwigExtension::tagList('["api","ui"]'));
        self::assertSame(['api'], TaskBoardTwigExtension::tagList('["api", 3, "", null]'));
        self::assertSame([], TaskBoardTwigExtension::tagList(null));
        self::assertSame([], TaskBoardTwigExtension::tagList(''));
        self::assertSame([], TaskBoardTwigExtension::tagList('not json'));
        self::assertSame([], TaskBoardTwigExtension::tagList('"api"'));
    }

    /** The deprecated function still reads the UiKit theme slot of the NowoTaskBoardBundle theme bundle. */
    #[IgnoreDeprecations]
    public function testButtonClassReadsTheUiKitThemeSlot(): void
    {
        $extension = new TaskBoardTwigExtension();
        $twig      = new Environment(new ArrayLoader());
        $twig->addExtension(new ThemeExtension(new Theme(bundles: [
            TaskBoardTwigExtension::THEME_BUNDLE => ['classes' => ['btn_secondary' => 'beacon-cancel']],
        ])));

        self::assertStringContainsString('beacon-cancel', $extension->buttonClass($twig, [], 'btn_secondary'));
        self::assertStringContainsString('nowo-ui-btn--secondary', $extension->buttonClass($twig, [], 'btn_secondary'));
        self::assertStringContainsString('btn-primary', $extension->buttonClass($twig, [], 'btn_primary'));
    }
}
