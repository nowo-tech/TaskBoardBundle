/**
 * The shipped IIFE entry logs the house "script loaded" line exactly once when it is evaluated.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@hotwired/stimulus', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@hotwired/stimulus')>();

    return { ...actual, Application: { start: () => ({ register: vi.fn() }) } };
});

describe('task-board entry', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.resetModules();
    });

    it('logs "script loaded" once, without a build id when none is injected', async () => {
        const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

        await import('./task-board');

        const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
        expect(lines).toHaveLength(1);
        expect(lines[0]).toContain('[task-board] script loaded');
        expect(lines[0]).not.toContain('build: ');
    });

    it('includes the injected build id (also stamped on the root element)', async () => {
        vi.stubGlobal('__TASK_BOARD_BUILD_TIME__', 'abc123def456');
        const log = vi.spyOn(console, 'log').mockImplementation(() => undefined);

        await import('./task-board');

        const lines = log.mock.calls.map((call) => String(call[0])).filter((line) => line.includes('script loaded'));
        expect(lines).toHaveLength(1);
        expect(lines[0]).toContain('[task-board] script loaded, build: ');
        expect(JSON.stringify(log.mock.calls)).toContain('abc123def456');
        expect(document.documentElement.dataset.taskBoardBuild).toBe('abc123def456');
    });
});
