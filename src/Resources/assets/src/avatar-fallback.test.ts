import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { fallBack, installAvatarFallback, isSafeAvatarUrl } from './avatar-fallback';

const chip = (src: string): string => `<span class="nowo-task-avatar nowo-task-member-avatar has-image" role="img" aria-label="Ada">A<img class="nowo-task-member-avatar__image" src="${src}" alt=""></span>`;
const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

let uninstall: (() => void) | null = null;

afterEach(() => {
    uninstall?.();
    uninstall = null;
    document.body.innerHTML = '';
});

describe('isSafeAvatarUrl', () => {
    // The same table as the PHP AvatarUrlPolicyTest.
    const table = JSON.parse(readFileSync(resolve(process.cwd(), 'tests/Fixtures/avatar-urls.json'), 'utf8')) as { url: string; allowed: boolean }[];

    it.each(table.map((row) => [row.url, row.allowed]))('%j → %s', (url, expected) => {
        expect(isSafeAvatarUrl(url as string)).toBe(expected);
    });

    it('refuses null', () => {
        expect(isSafeAvatarUrl(null)).toBe(false);
    });
});

describe('installAvatarFallback', () => {
    it('brings the initial back on error and does not retry a failed URL', async () => {
        uninstall = installAvatarFallback(document);
        document.body.innerHTML = chip('/files/1/s64') + chip('/files/2/s64');
        await flush();
        const first = document.querySelector('img') as HTMLImageElement;
        first.dispatchEvent(new Event('error'));
        expect(document.querySelectorAll('img')).toHaveLength(1);
        expect(document.querySelector('.nowo-task-member-avatar')?.classList.contains('has-image')).toBe(false);
        expect(document.querySelector('[role="img"]')?.getAttribute('aria-label')).toBe('Ada');

        // A later fragment (panel fetch) with the same URL is not requested again; another URL stays.
        const fragment = document.createElement('div');
        fragment.innerHTML = chip('/files/1/s64') + chip('/files/3/s64');
        document.body.append(fragment);
        await flush();
        expect(Array.from(fragment.querySelectorAll('img')).map((img) => img.getAttribute('src'))).toEqual(['/files/3/s64']);

        // A single added image and an unsafe one.
        const single = document.createElement('img');
        single.className = 'nowo-task-member-avatar__image';
        single.setAttribute('src', 'javascript:alert(1)');
        document.body.append(single);
        await flush();
        expect(single.isConnected).toBe(false);

        // Other images and errors are ignored.
        const other = document.createElement('img');
        document.body.append(other);
        other.dispatchEvent(new Event('error'));
        document.body.append(document.createTextNode('x'));
        await flush();
        expect(other.isConnected).toBe(true);
    });

    it('handles pictures that failed or are unsafe before the script ran', () => {
        document.body.innerHTML = chip('/files/ok') + chip('/files/broken') + chip('http://insecure.test/a.png') + '<img class="nowo-task-member-avatar__image" alt="">';
        const broken = document.querySelectorAll('img')[1] as HTMLImageElement;
        Object.defineProperty(broken, 'complete', { value: true });
        Object.defineProperty(broken, 'naturalWidth', { value: 0 });
        const ok = document.querySelectorAll('img')[0] as HTMLImageElement;
        Object.defineProperty(ok, 'complete', { value: false });
        uninstall = installAvatarFallback(document);

        expect(Array.from(document.querySelectorAll('img')).map((img) => img.getAttribute('src'))).toEqual(['/files/ok']);
    });

    it('fallBack works on an image without a chip', () => {
        const failed = new Set<string>();
        const image = document.createElement('img');
        document.body.append(image);
        fallBack(image, failed);
        expect(failed.has('')).toBe(true);
        expect(image.isConnected).toBe(false);
    });
});
