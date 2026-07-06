import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['src/Resources/assets/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'text-summary'],
            reportsDirectory: './coverage-ts',
            include: ['src/Resources/assets/src/list-filter-utils.ts'],
            exclude: ['**/*.test.ts', '**/node_modules/**'],
        },
    },
});
