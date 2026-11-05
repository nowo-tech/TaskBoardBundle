/**
 * Since 1.24 (help_link.anchor_from: source): the `#anchor` of an import help link follows the selected import
 * source — `taskboard_json` becomes `#taskboard-json` (lowercase, underscores to hyphens; anything outside
 * [a-z0-9-] drops the anchor). Same rule as the server-side render of `_import_help_link.html.twig`.
 */
export function sourceAnchor(value: string): string {
    const anchor = value.trim().toLowerCase().replace(/_/g, '-');

    return /^[a-z0-9-]+$/.test(anchor) ? anchor : '';
}

/** `href` with its fragment replaced by `anchor` (or removed when `anchor` is empty). */
export function withAnchor(href: string, anchor: string): string {
    const hash = href.indexOf('#');
    const base = hash === -1 ? href : href.slice(0, hash);

    return anchor === '' ? base : `${base}#${anchor}`;
}
