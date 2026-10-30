<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskComment;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Support\SubtaskProgress;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Nowo\TaskBoardBundle\Text\PlainTaskTextRenderer;
use Nowo\TaskBoardBundle\Text\TaskTextRendererInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function array_unshift;
use function count;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_replace;
use function strtolower;
use function strtr;
use function usort;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PHP_URL_SCHEME;

/**
 * Twig globals for the TaskBoard manage Web UI (REQ-UI-001).
 */
final class TaskBoardTwigExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @param array<string, array{path?: string, name?: string}> $routes Configured `routes` tree (logical key => path/name)
     */
    public function __construct(
        private readonly string $layoutTemplate,
        private readonly string $cssFramework,
        private readonly array $routes = [],
        private readonly TaskObjectLinkResolver $linkResolver = new TaskObjectLinkResolver(),
        private readonly ?string $filtersCollapse = null,
        private readonly string $filtersFormClass = '',
        private readonly TaskTextRendererInterface $textRenderer = new PlainTaskTextRenderer(),
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_task_board_route', $this->routeName(...)),
            new TwigFunction('nowo_task_board_link_label', $this->linkResolver->label(...)),
            new TwigFunction('nowo_task_board_link_url', $this->linkResolver->url(...)),
            new TwigFunction('nowo_task_board_link_badge', $this->linkBadge(...)),
            new TwigFunction('nowo_task_board_subtask_progress', $this->subtaskProgress(...)),
            new TwigFunction('nowo_task_board_deviation', TaskDeviation::of(...)),
            new TwigFunction('nowo_task_board_ancestors', self::ancestors(...)),
            new TwigFunction('nowo_task_board_comment_body', $this->renderCommentBody(...), ['is_safe' => ['html']]),
            new TwigFunction('nowo_task_board_text', $this->renderText(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Status badge of a host object link (since 1.10); null for other links.
     */
    public function linkBadge(TaskLink $link): ?TaskObjectBadge
    {
        return $this->linkResolver->badge($link);
    }

    /**
     * Done / total of a task's subtasks at any depth (since 1.10).
     */
    public function subtaskProgress(Task $task): SubtaskProgress
    {
        return SubtaskProgress::of($task);
    }

    /**
     * Since 1.15: the parents of a task from the top-level one down to its direct parent (at most 50 levels, so corrupted
     * cyclic data cannot loop).
     *
     * @return list<Task>
     */
    public static function ancestors(Task $task): array
    {
        $ancestors = [];
        $parent    = $task->getParent();
        while ($parent instanceof Task && count($ancestors) < 50) {
            array_unshift($ancestors, $parent);
            $parent = $parent->getParent();
        }

        return $ancestors;
    }

    /**
     * Since 1.16: a comment's text as HTML — escaped, line breaks kept, its stored mentions ("@Name") as chips
     * (`nowo-task-mention`, `nowo-task-mention--me` for the viewer). No other markup: the body is plain text.
     */
    public static function commentBody(TaskComment $comment, ?string $viewerId = null, TaskTextRendererInterface $renderer = new PlainTaskTextRenderer()): string
    {
        // Since 1.19 the body goes through the text renderer (plain by default, Markdown in a host): the mentions are
        // swapped for private-use placeholders first, so the renderer never sees (or breaks) them, then become chips.
        $text     = $comment->getBody();
        $mentions = $comment->getMentions();
        usort($mentions, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));
        $chips = [];
        foreach ($mentions as $index => $mention) {
            $placeholder         = "\u{E000}" . $index . "\u{E001}";
            $text                = str_replace('@' . $mention['label'], $placeholder, $text);
            $chips[$placeholder] = sprintf(
                '<span class="nowo-task-mention%s" data-user-id="%s">%s</span>',
                $mention['id'] === $viewerId ? ' nowo-task-mention--me' : '',
                htmlspecialchars($mention['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars('@' . $mention['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return strtr($renderer->render($text), $chips);
    }

    /**
     * Since 1.19: a comment body through the configured text renderer (Twig nowo_task_board_comment_body).
     */
    public function renderCommentBody(TaskComment $comment, ?string $viewerId = null): string
    {
        return self::commentBody($comment, $viewerId, $this->textRenderer);
    }

    /**
     * Since 1.19: user text (a plain-text description) through the configured text renderer (Twig nowo_task_board_text).
     */
    public function renderText(?string $text): string
    {
        return $text === null || trim($text) === '' ? '' : $this->textRenderer->render($text);
    }

    /**
     * Maps a logical route key (e.g. "board", "task_move") to the route name configured under
     * `nowo_task_board.routes.<key>.name`, so templates keep working when hosts rename routes.
     */
    public function routeName(string $key): string
    {
        $name = $this->routes[$key]['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'nowo_task_board_' . $key;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('nowo_task_board_safe_url', self::safeUrl(...)),
            new TwigFilter('nowo_task_board_safe_color', self::safeColor(...)),
            new TwigFilter('nowo_task_board_tag_list', self::tagList(...)),
            // Since 1.15: minutes as "45 min", "2 h", "1 h 30 min" (signed: "+20 min", "−1 h").
            new TwigFilter('nowo_task_board_duration', TaskDeviation::formatMinutes(...)),
        ];
    }

    /**
     * Render-time guard for link hrefs: only absolute http(s) URLs pass, anything else becomes "#".
     */
    public static function safeUrl(mixed $url): string
    {
        if (!is_string($url)) {
            return '#';
        }

        $url    = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '#';
    }

    /**
     * Render-time guard for colours interpolated into style="…": hex colours only, otherwise null.
     */
    public static function safeColor(mixed $color): ?string
    {
        if (!is_string($color) || preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $color) !== 1) {
            return null;
        }

        return $color;
    }

    /**
     * Decodes a tags change-history value (JSON list written by TaskChangeRecorder) into a list of
     * strings; null, invalid JSON or non-list payloads yield an empty list.
     *
     * @return list<string>
     */
    public static function tagList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        $tags = [];
        foreach ($decoded as $tag) {
            if (is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    public function getGlobals(): array
    {
        return [
            'nowo_task_board_layout'        => $this->layoutTemplate,
            'nowo_task_board_css_framework' => $this->cssFramework,
            // Since 1.17: the host's filter fold partial and filter card classes for the list view.
            'nowo_task_board_filters_collapse'   => $this->filtersCollapse,
            'nowo_task_board_filters_form_class' => $this->filtersFormClass,
        ];
    }
}
