<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

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
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_task_board_route', $this->routeName(...)),
        ];
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
