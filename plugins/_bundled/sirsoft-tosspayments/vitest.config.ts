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
            // 확장 디렉터리(plugins/_bundled/<id>)에서 저장소 루트까지는 세 단계다
            '@core': path.resolve(__dirname, '../../../resources/js/core'),
        },
    },
});
