import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

export default defineConfig({
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/__tests__/**/*.test.{ts,tsx}'],
        setupFiles: ['./resources/js/__tests__/setup.ts'],
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            '@core': resolve(__dirname, '../../../resources/js/core'),
        },
    },
});
