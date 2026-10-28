<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use Nowo\TaskBoardBundle\Entity\Task;
use Nowo\TaskBoardBundle\Entity\TaskLink;
use Nowo\TaskBoardBundle\Link\TaskObjectBadge;
use Nowo\TaskBoardBundle\Link\TaskObjectLinkResolver;
use Nowo\TaskBoardBundle\Support\SubtaskProgress;
use Nowo\TaskBoardBundle\Support\TaskDeviation;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function array_unshift;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function preg_match;
use function strtolower;

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
        ];
    }
}
