/**
 * Since 1.11 the templates carry no inline style (CSP without 'unsafe-inline'): an element lists its CSS custom
 * properties as JSON in data-nowo-task-vars (e.g. {"--nowo-task-column-color": "#16a34a"}) and this sets them through
 * the CSSOM, which a style-src CSP allows. Only custom properties (names starting with "--") with string or number
 * values are applied.
 */
export const VARS_ATTRIBUTE = 'data-nowo-task-vars';

export function applyCssVars(element: HTMLElement): void {
    const raw = element.getAttribute(VARS_ATTRIBUTE);
    if (raw === null || raw === '') {
        return;
    }

    let vars: unknown;
    try {
        vars = JSON.parse(raw);
    } catch {
        return;
    }

    if (vars === null || typeof vars !== 'object' || Array.isArray(vars)) {
        return;
    }

    for (const [name, value] of Object.entries(vars as Record<string, unknown>)) {
        if (name.startsWith('--') && (typeof value === 'string' || typeof value === 'number')) {
            element.style.setProperty(name, String(value));
        }
    }
}

export function applyCssVarsIn(root: ParentNode): void {
    if (root instanceof HTMLElement && root.hasAttribute(VARS_ATTRIBUTE)) {
        applyCssVars(root);
    }

    root.querySelectorAll<HTMLElement>(`[${VARS_ATTRIBUTE}]`).forEach(applyCssVars);
}

/**
 * Applies the variables of the page and of every element added later (a task panel loaded into a drawer).
 */
export function observeCssVars(root: HTMLElement): MutationObserver {
    applyCssVarsIn(root);
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    applyCssVarsIn(node);
                }
            });
        }
    });
    observer.observe(root, { childList: true, subtree: true });

    return observer;
}
