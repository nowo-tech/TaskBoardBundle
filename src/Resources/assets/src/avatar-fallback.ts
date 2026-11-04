/**
 * Since 1.23: task member pictures fall back to the initial chip — one capturing `error` listener on the document
 * (`error` does not bubble; capture sees it, also in panels fetched later), a start-up pass for pictures that failed
 * before the script ran, and an observer that drops new pictures whose URL already failed or is not allowed. Failed
 * URLs are remembered for the page's lifetime only.
 */
const IMAGE = 'img.nowo-task-member-avatar__image';

/**
 * The client copy of the URL policy (AvatarUrlPolicy): `https://host[:port]/…` without user info, IPv6 literal or a
 * bad port, or a same-origin path with one leading "/"; no controls, white space, backslash, quotes, `<`, `>` or
 * backtick, Unicode separators or control / format / lone surrogate characters, no port with a leading zero; at most
 * 2048 UTF-8 bytes. Both copies run tests/Fixtures/avatar-urls.json.
 */
export function isSafeAvatarUrl(url: string | null): boolean {
    if (url === null || url === '' || new TextEncoder().encode(url).length > 2048 || /[\u0000-\u0020\u007F\\"'<>`]/.test(url) || /[\p{Z}\p{C}]/u.test(url)) {
        return false;
    }
    if (url.startsWith('/')) {
        return !url.startsWith('//');
    }
    const match = /^https:\/\/[^/?#@:[\]]+(?::([1-9]\d{0,4}))?(?:[/?#].*)?$/is.exec(url);
    if (match === null) {
        return false;
    }
    const port = match[1] === undefined ? 0 : Number(match[1]);

    return match[1] === undefined || (port >= 1 && port <= 65535);
}

/** Removes a picture and shows the initial again; remembers its URL. */
export function fallBack(image: HTMLImageElement, failed: Set<string>): void {
    failed.add(image.getAttribute('src') ?? '');
    image.closest('.nowo-task-member-avatar')?.classList.remove('has-image');
    image.remove();
}

/** Whether a picture must not be shown (unsafe or already failed URL; `loaded`: also when it failed to load). */
function rejected(image: HTMLImageElement, failed: Set<string>, loaded: boolean): boolean {
    const src = image.getAttribute('src');

    return src === null || !isSafeAvatarUrl(src) || failed.has(src) || (loaded && image.complete && image.naturalWidth === 0);
}

/**
 * Installs the fallback on a document; returns a function that removes it (tests).
 */
export function installAvatarFallback(doc: Document): () => void {
    const failed = new Set<string>();
    const onError = (event: Event): void => {
        const target = event.target;
        if (target instanceof HTMLImageElement && target.matches(IMAGE)) {
            fallBack(target, failed);
        }
    };
    doc.addEventListener('error', onError, true);
    doc.querySelectorAll<HTMLImageElement>(IMAGE).forEach((image) => {
        if (rejected(image, failed, true)) {
            fallBack(image, failed);
        }
    });
    const observer = new MutationObserver((records) => {
        for (const record of records) {
            record.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    const images = node.matches(IMAGE) ? [node as HTMLImageElement] : Array.from(node.querySelectorAll<HTMLImageElement>(IMAGE));
                    images.forEach((image) => {
                        if (rejected(image, failed, false)) {
                            fallBack(image, failed);
                        }
                    });
                }
            });
        }
    });
    observer.observe(doc.documentElement, { childList: true, subtree: true });

    return () => {
        doc.removeEventListener('error', onError, true);
        observer.disconnect();
    };
}
