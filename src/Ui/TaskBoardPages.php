<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Ui;

use Nowo\TaskBoardBundle\Twig\TaskBoardTwigExtension;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Throwable;
use Twig\Environment;

use function in_array;
use function is_string;
use function trigger_deprecation;

/**
 * Since 2.0.0: page plumbing of the UiKit page contract (REQ-UI-003). It resolves the layout the pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`), wraps a pre-2.0 layout (one that renders `{% block body %}`
 * instead of the canonical blocks) in a deprecated bridge, and builds the page headers. Configuration only: no request
 * state, safe in worker mode.
 *
 * @phpstan-type HeaderSpec array{title: Label|string, intro?: Label|string|null, crumb?: array{href: string, label: Label|string}|null, actions?: list<array{href?: string, label: Label|string, icon?: string|null, variant?: string|null, attributes?: array<string, string|int|float|bool|null>, dialog?: string|null}>, back?: string|null, tabs?: list<array{href: string, label: Label|string, icon?: string|null, active?: bool}>}
 */
final readonly class TaskBoardPages
{
    /** Bundle name of the view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoTaskBoardBundle`). */
    public const BUNDLE = TaskBoardTwigExtension::THEME_BUNDLE;

    /** Translation domain of the bundle labels. */
    public const DOMAIN = 'NowoTaskBoardBundle';

    /** BC bridge around a pre-2.0 layout that renders `{% block body %}` (removed in 3.0). */
    public const LEGACY_ADMIN_BRIDGE = '@NowoTaskBoardBundle/manage/_legacy_layout.html.twig';

    /** The 1.x default of `templates.layout`: the removed bundle layout, read as "no layout override". */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoTaskBoardBundle/layout.html.twig';

    /** Id of the New board dialog of the index page. */
    public const BOARD_CREATE_DIALOG = 'nowo-task-board-create';

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /** Canonical blocks a pre-2.0 layout may already render (the bridge skips them). */
    private const LEGACY_HOST_BLOCKS = ['nowo_ui_flashes', 'nowo_ui_styles', 'nowo_ui_scripts'];

    public function __construct(private ?string $layoutTemplate = null)
    {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /** The layout the pages extend: `web_ui.layout_template`, the bridge for a pre-2.0 layout, else null (UiKit's). */
    public function layout(Environment $twig): ?string
    {
        $layout = $this->configuredLayout();
        if ($layout === null) {
            return null;
        }

        return $this->isLegacy($twig, $layout) ? self::LEGACY_ADMIN_BRIDGE : $layout;
    }

    /** The pre-2.0 layout the bridge extends; null when the configured layout renders the canonical blocks. */
    public function legacyLayout(Environment $twig): ?string
    {
        $layout = $this->configuredLayout();

        return $layout !== null && $this->isLegacy($twig, $layout) ? $layout : null;
    }

    /**
     * The canonical blocks a pre-2.0 layout already renders, so the bridge does not print them twice.
     *
     * @return list<string>
     */
    public function legacyBlocks(Environment $twig): array
    {
        $layout = $this->legacyLayout($twig);
        if ($layout === null) {
            return [];
        }
        $template = $twig->load($layout);
        $blocks   = [];
        foreach (self::LEGACY_HOST_BLOCKS as $block) {
            if ($template->hasBlock($block)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The page header of a page, from a hash a template can write (Twig `nowo_task_board_header`).
     *
     * `actions`: hashes of `href`, `label` and optionally `icon` (a Tabler name), `variant`, `attributes` and `dialog`
     * (the id of a dialog the action opens). `back`: a URL that adds the host's way back, the 1.x `dashboardRoute` action.
     * Text arrives already translated, as in the templates that call this.
     *
     * @param array{title: Label|string, intro?: Label|string|null, crumb?: array{href: string, label: Label|string}|null, actions?: list<array{href?: string, label: Label|string, icon?: string|null, variant?: string|null, attributes?: array<string, bool|float|int|string|null>, dialog?: string|null}>, back?: string|null, tabs?: list<array{href: string, label: Label|string, icon?: string|null, active?: bool}>} $spec
     */
    public static function header(array $spec, string $iconSet = 'tabler-icons'): PageHeader
    {
        $actions = [];
        foreach ($spec['actions'] ?? [] as $action) {
            $icon      = $action['icon'] ?? null;
            $actions[] = new Action(
                label: $action['label'],
                href: $action['href'] ?? null,
                icon: is_string($icon) && $iconSet === 'ux_icon' ? 'tabler:' . $icon : $icon,
                variant: $action['variant'] ?? null,
                attributes: $action['attributes'] ?? [],
                dialogId: $action['dialog'] ?? null,
            );
        }
        if (is_string($spec['back'] ?? null)) {
            $actions[] = new Action(label: self::label('task_board.action.back'), href: $spec['back']);
        }
        $tabs = [];
        foreach ($spec['tabs'] ?? [] as $tab) {
            $icon   = $tab['icon'] ?? null;
            $tabs[] = new AreaNavItem($tab['label'], $tab['href'], $tab['active'] ?? false, icon: is_string($icon) && $iconSet === 'ux_icon' ? 'tabler:' . $icon : $icon);
        }
        $crumb = $spec['crumb'] ?? null;

        return new PageHeader(
            title: $spec['title'],
            intro: $spec['intro'] ?? null,
            actions: $actions,
            breadcrumbs: $crumb !== null ? [new Crumb($crumb['label'], $crumb['href'])] : [],
            tabs: $tabs !== [] ? new AreaNav($tabs, self::label('task_board.view.label')) : null,
            breadcrumbsMode: 'own',
        );
    }

    /** The configured layout, with the removed bundle layout read as "none". */
    private function configuredLayout(): ?string
    {
        return in_array($this->layoutTemplate, [null, '', self::LEGACY_DEFAULT_LAYOUT], true) ? null : $this->layoutTemplate;
    }

    private function isLegacy(Environment $twig, string $layout): bool
    {
        try {
            $legacy = !$twig->load($layout)->hasBlock(self::CANONICAL_BLOCK);
        } catch (Throwable) {
            // A layout that does not load fails where the page extends it, with Twig's own message.
            return false;
        }
        if ($legacy) {
            trigger_deprecation('nowo-tech/task-board-bundle', '2.0', 'The layout "%s" renders no UiKit canonical block: it goes through a bridge removed in 3.0. Render nowo_ui_page_header, nowo_ui_flashes, nowo_ui_content and nowo_ui_modals, as docs/UPGRADING.md shows.', $layout);
        }

        return $legacy;
    }
}
