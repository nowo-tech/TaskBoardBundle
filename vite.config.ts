import { defineConfig } from 'vite';

export default defineConfig({
    define: {
        __TASK_BOARD_BUILD_TIME__: JSON.stringify(new Date().toISOString()),
    },
    build: {
        outDir: 'src/Resources/public',
        emptyOutDir: false,
        rollupOptions: {
            input: 'src/Resources/assets/src/task-board.ts',
            output: {
                format: 'iife',
                entryFileNames: 'js/task-board.js',
                assetFileNames: 'js/task-board.[ext]',
            },
        },
        minify: true,
        sourcemap: false,
    },
});
