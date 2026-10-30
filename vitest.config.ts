import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['src/Resources/assets/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'text-summary'],
            reportsDirectory: './coverage-ts',
            include: [
                'src/Resources/assets/src/list-filter-utils.ts',
                'src/Resources/assets/src/css-vars.ts',
                'src/Resources/assets/src/storage.ts',
                'src/Resources/assets/src/time-utils.ts',
                'src/Resources/assets/src/option-filter-utils.ts',
                'src/Resources/assets/src/filter-collapse-utils.ts',
                'src/Resources/assets/src/gantt-scale.ts',
                'src/Resources/assets/src/list-sort-utils.ts',
                'src/Resources/assets/src/mention-utils.ts',
                'src/Resources/assets/src/task-nav-utils.ts',
            ],
            thresholds: { lines: 100, functions: 100, branches: 100, statements: 100 },
            exclude: ['**/*.test.ts', '**/node_modules/**'],
        },
    },
});
