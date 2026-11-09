import { defineConfig, devices } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** The demo PORT from the sibling .env / .env.example (demo/symfony8). */
function resolvePort(fallback = 8024): number {
  for (const file of ['.env', '.env.example']) {
    const path = resolve(__dirname, '..', file);
    if (!existsSync(path)) {
      continue;
    }
    const match = readFileSync(path, 'utf8').match(/^PORT=(\d+)/m);
    if (match) {
      return Number(match[1]);
    }
  }
  return fallback;
}

const baseURL = process.env.DEMO_BASE_URL || `http://127.0.0.1:${resolvePort()}`;

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: { baseURL, trace: 'retain-on-failure', viewport: { width: 1280, height: 900 } },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'phone', use: { ...devices['Pixel 7'] } },
    // Since 2.0.0: the screens that moved to UiKit run in every engine: `--project=firefox --project=webkit`.
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    // Since 2.0.1 (audit HY-A): the board index as a card on an iPhone profile and in a narrow Firefox window.
    { name: 'iphone', testMatch: /board-index-phone/, use: { ...devices['iPhone 13'] } },
    { name: 'firefox-narrow', testMatch: /board-index-phone/, use: { ...devices['Desktop Firefox'], viewport: { width: 390, height: 844 } } },
    // Since 2.8.0 (host mobile UX audit MUX-70, MUX-73, MUX-74): the phone layout at 390 × 844 in Chromium and WebKit.
    { name: 'phone-chromium', testMatch: /phone-layout/, use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } } },
    { name: 'phone-webkit', testMatch: /phone-layout/, use: { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } } },
  ],
});
