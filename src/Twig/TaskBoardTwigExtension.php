<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

use function in_array;
use function is_string;
use function parse_url;
use function preg_match;
use function strtolower;

use const PHP_URL_SCHEME;

/**
 * Twig globals for the TaskBoard manage Web UI (REQ-UI-001).
 */
final class TaskBoardTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly string $layoutTemplate,
        private readonly string $cssFramework,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('nowo_task_board_safe_url', self::safeUrl(...)),
            new TwigFilter('nowo_task_board_safe_color', self::safeColor(...)),
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

    public function getGlobals(): array
    {
        return [
            'nowo_task_board_layout'        => $this->layoutTemplate,
            'nowo_task_board_css_framework' => $this->cssFramework,
        ];
    }
}
