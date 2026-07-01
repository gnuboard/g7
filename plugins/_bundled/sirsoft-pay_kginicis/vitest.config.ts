import { defineConfig } from 'vitest/config';
import path from 'path';

export default defineConfig({
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@core': path.resolve(__dirname, '../../resources/js/core'),
        },
    },
});
